<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Activity\Events\ActivityLogPurgeQueued;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Support\ActivityPurgeCriteria;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/**
 * Schedules recoverable retention work in exactly the current admitted scope.
 *
 * @api
 */
final readonly class ActivityPurgeDispatcher
{
    public function __construct(
        private OwnershipContext $ownership,
        private OwnershipBoundary $boundary,
        private ActivityAccess $access,
        private DomainEventDispatcher $events,
    ) {}

    /** Dispatch after the actual Activity connection's outer commit; rollback cancels delivery. */
    public function dispatch(ActivityPurgeCriteria $criteria, Authenticatable|SystemActor $actor): int
    {
        $this->access->authorize($actor, 'purge');
        $this->boundary->attributes('activity.events');
        OwnershipJobEnvelope::capture($this->ownership);
        $job = new PurgeActivityLogsJob($criteria->days ?? 0, $criteria->systemOnly, $criteria);
        $connection = (ActivityModelRegistry::newInstance())->getConnection();
        $dispatch = static fn () => dispatch($job);
        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($dispatch);
        } else {
            $dispatch();
        }
        $this->events->dispatch(new ActivityLogPurgeQueued($criteria->days ?? 0, $criteria->systemOnly, $criteria->includeImportant), $connection);

        return 1;
    }

    /** Count only the same admitted scope that dispatch will capture. */
    public function count(ActivityPurgeCriteria $criteria, Authenticatable|SystemActor $actor): int
    {
        $this->access->authorize($actor, 'purge');

        return PurgeActivityLogsJob::countPurgeableForCriteria($criteria, $this->boundary);
    }
}
