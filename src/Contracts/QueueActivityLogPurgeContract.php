<?php

declare(strict_types=1);

namespace Nvl\Activity\Contracts;

/**
 * Defines the application boundary for queuing validated activity retention work.
 */
interface QueueActivityLogPurgeContract
{
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
    ): void;
}
