<?php

declare(strict_types=1);

namespace Nvl\Activity\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Nvl\Activity\Builders\ActivityLogBuilder;
use Nvl\Activity\Services\ActivityModelRegistry;
use Nvl\Activity\Services\ActivityPolicyRegistry;
use Nvl\Activity\Services\ActivityRetentionContext;
use Nvl\Activity\Support\ActivityPurgeCriteria;
use Nvl\Support\Config\PackageOptions;
use Nvl\Support\Facades\PackageLog;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Traits\CapturesOwnership;
use Throwable;
use UnexpectedValueException;

/**
 * Queued job that deletes old activity log entries in chunks.
 *
 * One run deletes a bounded number of rows and hands the rest to a new job that
 * continues after the last row it handled, so a run stays far below its timeout.
 */
final class PurgeActivityLogsJob implements OwnershipQueuedJob, ShouldQueue
{
    use CapturesOwnership;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @var int Chunk size for batch deletion
     */
    private const int CHUNK_SIZE = 1000;

    /** Chunks one run deletes before it continues in a new job. */
    private const int CHUNKS_PER_RUN = 10;

    private const int LOCK_RETRY_DELAY_SECONDS = 60;

    /**
     * Queue delays applied after unhandled execution exceptions.
     *
     * @var list<int>
     */
    private const array EXCEPTION_BACKOFF_SECONDS = [60, 300, 900, 1800];

    /** Maximum execution time that queue visibility must exceed. */
    public const int TIMEOUT_SECONDS = 900;

    /** Maximum number of unhandled execution exceptions before failure. */
    public int $maxExceptions = 5;

    /** Maximum execution time for one purge attempt. */
    public int $timeout = self::TIMEOUT_SECONDS;

    /** Mark the job as failed when its worker timeout is reached. */
    public bool $failOnTimeout = true;

    /** Continuation cursor: stored timestamp of the last handled row. Defaulted so payloads queued before continuations still run. */
    public ?string $afterCreatedAt = null;

    /** Continuation cursor: identifier of the last handled row. */
    public ?string $afterId = null;

    /** Rows deleted by the earlier runs of this purge. */
    public int $deletedBefore = 0;

    /**
     * Create a new job instance.
     *
     * @param  int  $days  Delete entries older than this many days
     * @param  bool  $systemOnly  Only delete system-originated entries
     * @param  ActivityPurgeCriteria|null  $criteria  Immutable serialized purge filters
     * @param  string|null  $afterCreatedAt  Continuation cursor: stored timestamp of the last handled row
     * @param  string|null  $afterId  Continuation cursor: identifier of the last handled row
     * @param  int  $deletedBefore  Rows deleted by the earlier runs of this purge
     */
    public function __construct(
        public readonly int $days,
        public readonly bool $systemOnly = false,
        public readonly ?ActivityPurgeCriteria $criteria = null,
        ?string $afterCreatedAt = null,
        ?string $afterId = null,
        int $deletedBefore = 0,
    ) {
        $this->afterCreatedAt = $afterCreatedAt;
        $this->afterId = $afterId;
        $this->deletedBefore = $deletedBefore;
        $this->captureOwnership();
        $this->onConnection(PackageOptions::queueConnection('activity'));
        $this->onQueue(PackageOptions::queueName('activity'));
        $this->afterCommit();
    }

    /**
     * Execute the job: delete one bounded run of old activity logs, then continue in a new job.
     */
    public function handle(OwnershipBoundary $boundary): void
    {
        $criteria = $this->resolvedCriteria();
        $lock = Cache::lock(
            'nvl:activity:purge:'.hash('sha256', $boundary->key('activity.events', 'purge')),
            $this->lockSeconds(),
        );

        if (! $lock->get()) {
            PackageLog::log('activity', 'notice', 'nvl.activity.purge.delayed', [], 'normal');
            $this->release(self::LOCK_RETRY_DELAY_SECONDS);

            return;
        }

        try {
            $continuation = app(ActivityRetentionContext::class)->run(fn (): ?self => $this->purge($criteria, $boundary));
        } finally {
            $lock->release();
        }

        if ($continuation !== null) {
            dispatch($continuation);
        }
    }

    /**
     * Return exponential queue delays for failed purge attempts.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return self::EXCEPTION_BACKOFF_SECONDS;
    }

    /**
     * Allow lock-contention releases through one full lock lifetime while
     * retaining bounded retries for genuine execution exceptions.
     */
    public function retryUntil(): DateTimeInterface
    {
        $exceptionRetrySeconds = (max(self::EXCEPTION_BACKOFF_SECONDS)
            * max(0, $this->maxExceptions - 1))
            + (self::TIMEOUT_SECONDS * $this->maxExceptions);

        return now()->addSeconds(
            $this->lockSeconds()
            + $exceptionRetrySeconds
            + self::LOCK_RETRY_DELAY_SECONDS,
        );
    }

    /**
     * Report a purge that exhausted its queue attempts or timed out.
     */
    public function failed(?Throwable $exception): void
    {
        PackageLog::log('activity', 'error', 'nvl.activity.purge.failed', [
            'days' => $this->days,
            'system_only' => $this->systemOnly,
            'include_important' => $this->criteria instanceof ActivityPurgeCriteria
                ? $this->criteria->includeImportant
                : false,
            'exception' => $exception?->getMessage(),
        ], 'normal');
    }

    /**
     * Delete one bounded run of rows matching the immutable criteria, oldest first.
     *
     * @return self|null The job continuing after the last handled row, or null once the purge is complete.
     */
    private function purge(ActivityPurgeCriteria $criteria, OwnershipBoundary $boundary): ?self
    {
        $totalDeleted = $this->deletedBefore;
        $afterCreatedAt = $this->afterCreatedAt;
        $afterId = $this->afterId;

        for ($chunk = 0; $chunk < self::CHUNKS_PER_RUN; $chunk++) {
            $rows = $this->purgeableQuery($criteria, $boundary)
                ->when($afterCreatedAt !== null && $afterId !== null, static fn (Builder $query) => $query->where(
                    static fn (Builder $after) => $after->where('created_at', '>', $afterCreatedAt)
                        ->orWhere(static fn (Builder $same) => $same->where('created_at', $afterCreatedAt)->where('id', '>', $afterId)),
                ))
                ->oldestFirst()
                ->limit(self::CHUNK_SIZE)
                ->get(['id', 'created_at']);
            $last = $rows->last();

            if ($last === null) {
                break;
            }

            $query = $boundary->query(ActivityModelRegistry::query(), 'activity.events')->whereKey($rows->modelKeys());
            if (isset($boundary->attributes('activity.events')['owner_scope_key'])) {
                $deleted = 0;
                foreach ($query->get() as $activity) {
                    if ($activity->delete() !== true) {
                        throw new UnexpectedValueException('An owned audit deletion was cancelled.');
                    }
                    $deleted++;
                }
            } else {
                $deleted = $query->delete();
            }

            if (! is_int($deleted)) {
                throw new UnexpectedValueException('Activity purge did not return a deletion count.');
            }

            $totalDeleted += $deleted;
            $createdAt = $last->getRawOriginal('created_at');
            $afterCreatedAt = is_string($createdAt) ? $createdAt : null;
            $afterId = $last->id;

            if ($rows->count() < self::CHUNK_SIZE) {
                break;
            }

            if ($chunk === self::CHUNKS_PER_RUN - 1) {
                return new self($this->days, $this->systemOnly, $this->criteria, $afterCreatedAt, $afterId, $totalDeleted);
            }
        }

        PackageLog::log('activity', 'info', 'nvl.activity.purge.completed', [
            'deleted' => $totalDeleted,
            'days' => $criteria->days,
            'before' => $criteria->cutoff()->toIso8601String(),
            'after' => $criteria->afterCutoff()?->toIso8601String(),
            'system_only' => $criteria->systemOnly,
            'include_important' => $criteria->includeImportant,
            'events' => $criteria->events,
            'log_names' => $criteria->logNames,
            'subject_types' => $criteria->subjectTypes,
            'subject_ids' => $criteria->subjectIds,
            'causer_types' => $criteria->causerTypes,
            'causer_ids' => $criteria->causerIds,
        ], 'normal');

        return null;
    }

    /**
     * Count entries that would be purged without deleting them.
     *
     * @param  int  $days  Entries older than this many days
     * @param  bool  $systemOnly  Only count system-generated entries
     * @return int Number of purgeable entries
     */
    public static function countPurgeable(int $days, bool $systemOnly = false, ?OwnershipBoundary $boundary = null): int
    {
        return self::countPurgeableForCriteria(ActivityPurgeCriteria::fromDays($days, $systemOnly), $boundary);
    }

    /**
     * Count entries matching the supplied purge criteria without deleting them.
     */
    public static function countPurgeableForCriteria(ActivityPurgeCriteria $criteria, ?OwnershipBoundary $boundary = null): int
    {
        return self::newPurgeableQuery($criteria, $boundary)->count();
    }

    /**
     * Build the shared purge eligibility query.
     */
    private function purgeableQuery(ActivityPurgeCriteria $criteria, OwnershipBoundary $boundary): ActivityLogBuilder
    {
        return self::newPurgeableQuery($criteria, $boundary);
    }

    /**
     * Build the shared purge eligibility query.
     */
    private static function newPurgeableQuery(ActivityPurgeCriteria $criteria, ?OwnershipBoundary $boundary): ActivityLogBuilder
    {
        $query = ActivityModelRegistry::query();
        if ($boundary !== null) {
            $query = $boundary->query($query, 'activity.events');
        }
        if (! $query instanceof ActivityLogBuilder) {
            throw new UnexpectedValueException('Activity purge requires the canonical Activity query builder.');
        }

        app(ActivityPolicyRegistry::class)->retainable($query);

        return $query->applyPurgeCriteria($criteria);
    }

    /**
     * Resolve the effective purge criteria for this job execution.
     */
    private function resolvedCriteria(): ActivityPurgeCriteria
    {
        return $this->criteria ?? ActivityPurgeCriteria::fromDays($this->days, $this->systemOnly);
    }

    /**
     * Resolve the lock lifetime enforced for both execution and retry policy.
     */
    private function lockSeconds(): int
    {
        $configuredLockSeconds = config('nvl-activity.retention.lock_seconds', 3600);

        return max(
            self::TIMEOUT_SECONDS + self::LOCK_RETRY_DELAY_SECONDS,
            is_int($configuredLockSeconds) ? $configuredLockSeconds : 3600,
        );
    }
}
