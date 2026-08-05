<?php

declare(strict_types=1);

namespace Nvl\Activity\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Announces that a validated activity purge was queued after transaction commit.
 */
final class ActivityLogPurgeQueuedEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create the immutable queued-purge event payload.
     */
    public function __construct(
        public readonly int $days,
        public readonly bool $systemOnly,
    ) {}
}
