<?php

declare(strict_types=1);

namespace Nvl\Activity\Actions\Activity;

use Illuminate\Support\Facades\DB;
use Nvl\Activity\Contracts\QueueActivityLogPurgeContract;
use Nvl\Activity\Events\ActivityLogPurgeQueuedEvent;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Support\ActivityPurgeCriteria;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;

/**
 * Queue purge work for activity logs.
 */
final readonly class QueueActivityLogPurgeAction implements QueueActivityLogPurgeContract
{
    /** Create the tenant-aware queue boundary. */
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * Queue the activity log purge job.
     *
     * @param  int  $days  Delete logs older than this many days
     * @param  bool  $systemOnly  Only purge system-generated logs
     * @param  bool  $includeImportant  Explicitly include protected important evidence
     */
    public function execute(
        int $days,
        bool $systemOnly = false,
        bool $includeImportant = false,
    ): void {
        $criteria = ActivityPurgeCriteria::fromDays($days, $systemOnly, $includeImportant);

        $envelope = TenantJobEnvelope::capture($this->tenantContext);
        PurgeActivityLogsJob::dispatch($days, $systemOnly, $criteria, $envelope);

        DB::afterCommit(static function () use ($days, $systemOnly, $includeImportant): void {
            event(new ActivityLogPurgeQueuedEvent($days, $systemOnly, $includeImportant));
        });
    }
}
