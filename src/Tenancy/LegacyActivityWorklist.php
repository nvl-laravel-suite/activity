<?php

declare(strict_types=1);

namespace Nvl\Activity\Tenancy;

use Illuminate\Contracts\Config\Repository;
use Nvl\Activity\Contracts\ActivityTenantWorklist;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Contracts\TenantRunner;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Support\Tenancy\ValueObjects\TenantId;
use Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope;

/** Retains the bounded V1 retention worklist while that authority remains enabled. @internal */
final readonly class LegacyActivityWorklist
{
    public function __construct(private Repository $config, private TenantContext $context, private TenantRunner $tenants, private ActivityTenantWorklist $worklist) {}

    /** Capture only the deprecated scalar envelope for existing V1 consumers. */
    public function envelope(): TenantJobEnvelope
    {
        return TenantJobEnvelope::capture($this->context);
    }

    /** Execute one reviewed V1 worklist without granting ownership runtime authority. */
    public function each(callable $operation): int
    {
        if ($this->config->get('nvl-tenancy.enabled') !== true) {
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
