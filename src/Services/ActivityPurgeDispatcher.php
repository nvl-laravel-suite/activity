<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\Activity\Contracts\ActivityTenantWorklist;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Support\ActivityPurgeCriteria;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;

/** Dispatches/counts retention work only inside explicit bounded tenant scopes. */
final readonly class ActivityPurgeDispatcher
{
    public function __construct(
        private Repository $config,
        private TenantContext $context,
        private TenantRunner $tenants,
        private ActivityTenantWorklist $worklist,
        private TenantBoundary $boundary,
    ) {}

    /** Return the number of bounded jobs dispatched. */
    public function dispatch(ActivityPurgeCriteria $criteria): int
    {
        return $this->each(function () use ($criteria): void {
            PurgeActivityLogsJob::dispatch(
                $criteria->days ?? 0,
                $criteria->systemOnly,
                $criteria,
                TenantJobEnvelope::capture($this->context),
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
        if ($this->config->get('tenancy.enabled') !== true) {
            $operation();

            return 1;
        }
        $snapshot = $this->context->snapshot();
        if ($snapshot->mode === TenantContextMode::Tenant) {
            $operation();

            return 1;
        }
        if (! in_array($snapshot->mode, [TenantContextMode::Unresolved, TenantContextMode::Platform], true)) {
            throw new TenantBoundaryViolation('Activity retention requires an admitted tenant or platform worklist.');
        }
        $ids = $snapshot->mode === TenantContextMode::Platform
            ? $this->worklist->activeTenantIds()
            : $this->tenants->platform(
                new PlatformOperation('activity.retention.enumerate', 'system', 'activity-retention'),
                fn (): array => $this->worklist->activeTenantIds(),
            );
        foreach ($ids as $id) {
            $this->tenants->run(new TenantId($id), fn (): mixed => $operation());
        }

        return count($ids);
    }
}
