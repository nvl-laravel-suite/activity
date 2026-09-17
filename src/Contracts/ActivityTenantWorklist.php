<?php

declare(strict_types=1);

namespace Nvl\Activity\Contracts;

/** Host-supplied bounded active-tenant enumeration for retention maintenance. */
interface ActivityTenantWorklist
{
    /** @return list<string> */
    public function activeTenantIds(): array;
}
