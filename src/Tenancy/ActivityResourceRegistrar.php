<?php

declare(strict_types=1);

namespace Nvl\Activity\Tenancy;

use Nvl\Activity\Models\ActivityLog;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Support\Tenancy\ValueObjects\TenantResourceDefinition;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;

/** Declares independently owned audit facts, including explicit operational platform history. */
final readonly class ActivityResourceRegistrar
{
    /** Register immutable resource and adoption declarations before tenant admission. */
    public function register(TenantResourceRegistry $resources, ?TenantAdoptionRegistry $adapters = null): void
    {
        $resources->register(new TenantResourceDefinition('activity.events', 'activity', ActivityLog::class, allowsPlatformRows: true));
        $adapters?->register('activity', ActivityAdoptionAdapter::class);
    }
}
