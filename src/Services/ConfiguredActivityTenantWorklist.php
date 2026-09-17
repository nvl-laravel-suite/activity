<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use Nvl\Activity\Contracts\ActivityTenantWorklist;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;

/** Conservative worklist populated only by reviewed deployment configuration. */
final readonly class ConfiguredActivityTenantWorklist implements ActivityTenantWorklist
{
    public function __construct(private Repository $configuration) {}

    public function activeTenantIds(): array
    {
        $configured = $this->configuration->get('activity.tenancy.active_tenant_worklist', []);
        if (! is_array($configured) || ! array_is_list($configured)
            || array_any($configured, static fn (mixed $id): bool => ! is_string($id) || ! Str::isUuid($id))) {
            throw new TenantConfigurationInvalid('The Activity tenant worklist must be a list of canonical UUIDs.');
        }
        $ids = array_values(array_unique($configured));
        sort($ids);

        return $ids;
    }
}
