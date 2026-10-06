<?php

declare(strict_types=1);

namespace Nvl\Activity\Events;

use Nvl\Support\Contracts\DomainEvent;

/**
 * Announces that a validated activity purge was queued after transaction commit.
 *
 * @api
 */
final class ActivityLogPurgeQueued implements DomainEvent
{
    /**
     * Create the immutable queued-purge event payload.
     */
    public function __construct(
        public readonly int $days,
        public readonly bool $systemOnly,
        public readonly bool $includeImportant = false,
        public readonly int $schemaVersion = 1,
    ) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
