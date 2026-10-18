<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Support\ActivityPurgeCriteria;
use Nvl\Activity\Tenancy\LegacyActivityWorklist;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/** Dispatches/counts retention work only inside explicit bounded tenant scopes. */
final readonly class ActivityPurgeDispatcher
{
    public function __construct(
        private OwnershipContext $ownership,
        private OwnershipBoundary $boundary,
        private LegacyActivityWorklist $legacy,
    ) {}

    /** Return the number of bounded jobs dispatched. */
    public function dispatch(ActivityPurgeCriteria $criteria): int
    {
        return $this->each(function () use ($criteria): void {
            PurgeActivityLogsJob::dispatch(
                $criteria->days ?? 0,
                $criteria->systemOnly,
                $criteria,
                $this->legacy->envelope(),
            );
        });
    }

    /** Count matching rows across the same explicit worklist without widening a query. */
    public function count(ActivityPurgeCriteria $criteria): int
    {
        $count = 0;
        $this->each(function () use ($criteria, &$count): void {
            $count += PurgeActivityLogsJob::countPurgeableForCriteria($criteria, $this->boundary);
        });

        return $count;
    }

    /** Execute once in disabled/current tenant mode or once per reviewed platform worklist entry. */
    private function each(callable $operation): int
    {
        if ($this->ownership->snapshot()->mode !== OwnershipContextMode::Disabled) {
            OwnershipJobEnvelope::capture($this->ownership);
            $operation();

            return 1;
        }

        return $this->legacy->each($operation);
    }
}
