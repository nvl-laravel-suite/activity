<?php

declare(strict_types=1);

namespace Nvl\Activity\Actions\Activity;

use Illuminate\Support\Facades\DB;
use Nvl\Activity\Contracts\QueueActivityLogPurgeContract;
use Nvl\Activity\Events\ActivityLogPurgeQueuedEvent;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Support\ActivityPurgeCriteria;

/**
 * Queue purge work for activity logs.
 */
final class QueueActivityLogPurgeAction implements QueueActivityLogPurgeContract
{
    /**
     * Queue the activity log purge job.
     *
     * @param  int  $days  Delete logs older than this many days
     * @param  bool  $systemOnly  Only purge system-generated logs
     */
    public function execute(int $days, bool $systemOnly = false): void
    {
        ActivityPurgeCriteria::fromDays($days, $systemOnly);

        PurgeActivityLogsJob::dispatch($days, $systemOnly);

        DB::afterCommit(static function () use ($days, $systemOnly): void {
            event(new ActivityLogPurgeQueuedEvent($days, $systemOnly));
        });
    }
}
