<?php

declare(strict_types=1);

namespace Nvl\Activity\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Nvl\Activity\Builders\ActivityLogBuilder;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Support\ActivityPurgeCriteria;
use Throwable;
use UnexpectedValueException;

/**
 * Queued job that deletes old activity log entries in chunks.
 */
final class PurgeActivityLogsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @var int Chunk size for batch deletion
     */
    private const int CHUNK_SIZE = 1000;

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

    /**
     * Create a new job instance.
     *
     * @param  int  $days  Delete entries older than this many days
     * @param  bool  $systemOnly  Only delete system-originated entries
     * @param  ActivityPurgeCriteria|null  $criteria  Immutable serialized purge filters
     */
    public function __construct(
        public readonly int $days,
        public readonly bool $systemOnly = false,
        public readonly ?ActivityPurgeCriteria $criteria = null,
    ) {
        $configuredQueue = config('activity.retention.queue', 'maintenance');
        $this->onQueue(is_string($configuredQueue) ? $configuredQueue : 'maintenance');
        $this->afterCommit();
    }

    /**
     * Execute the job: delete old activity logs in chunks.
     */
    public function handle(): void
    {
        $criteria = $this->resolvedCriteria();
        $lock = Cache::lock(
            'nvl:activity:purge',
            $this->lockSeconds(),
        );

        if (! $lock->get()) {
            Log::notice('Activity log purge delayed because another purge owns the lock.');
            $this->release(self::LOCK_RETRY_DELAY_SECONDS);

            return;
        }

        try {
            $this->purge($criteria);
        } finally {
            $lock->release();
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
        Log::error('Activity log purge failed.', [
            'days' => $this->days,
            'system_only' => $this->systemOnly,
            'include_important' => $this->criteria instanceof ActivityPurgeCriteria
                ? $this->criteria->includeImportant
                : false,
            'exception' => $exception?->getMessage(),
        ]);
    }

    /**
     * Delete all rows matching the immutable criteria in bounded chunks.
     */
    private function purge(ActivityPurgeCriteria $criteria): void
    {
        $totalDeleted = 0;

        while (true) {
            $ids = $this->purgeableQuery($criteria)
                ->oldestFirst()
                ->limit(self::CHUNK_SIZE)
                ->pluck('id')
                ->filter(static fn (mixed $id): bool => is_string($id) || is_int($id))
                ->map(static fn (string|int $id): string => (string) $id)
                ->all();

            if ($ids === []) {
                break;
            }

            $deleted = ActivityLog::query()
                ->whereKey($ids)
                ->delete();

            if (! is_int($deleted)) {
                throw new UnexpectedValueException('Activity purge did not return a deletion count.');
            }

            $totalDeleted += $deleted;

            if ($deleted === 0 || count($ids) < self::CHUNK_SIZE) {
                break;
            }
        }

        Log::info('Activity log purge completed.', [
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
        ]);
    }

    /**
     * Count entries that would be purged without deleting them.
     *
     * @param  int  $days  Entries older than this many days
     * @param  bool  $systemOnly  Only count system-generated entries
     * @return int Number of purgeable entries
     */
    public static function countPurgeable(int $days, bool $systemOnly = false): int
    {
        return self::countPurgeableForCriteria(ActivityPurgeCriteria::fromDays($days, $systemOnly));
    }

    /**
     * Count entries matching the supplied purge criteria without deleting them.
     */
    public static function countPurgeableForCriteria(ActivityPurgeCriteria $criteria): int
    {
        return self::newPurgeableQuery($criteria)->count();
    }

    /**
     * Build the shared purge eligibility query.
     */
    private function purgeableQuery(ActivityPurgeCriteria $criteria): ActivityLogBuilder
    {
        return self::newPurgeableQuery($criteria);
    }

    /**
     * Build the shared purge eligibility query.
     */
    private static function newPurgeableQuery(ActivityPurgeCriteria $criteria): ActivityLogBuilder
    {
        return ActivityLog::query()->applyPurgeCriteria($criteria);
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
        $configuredLockSeconds = config('activity.retention.lock_seconds', 3600);

        return max(
            self::TIMEOUT_SECONDS + self::LOCK_RETRY_DELAY_SECONDS,
            is_int($configuredLockSeconds) ? $configuredLockSeconds : 3600,
        );
    }
}
