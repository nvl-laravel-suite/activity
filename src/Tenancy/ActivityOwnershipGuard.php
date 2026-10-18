<?php

declare(strict_types=1);

namespace Nvl\Activity\Tenancy;

use Nvl\Activity\Ownership\ActivityOwnershipGuard;

/** @deprecated Use the neutral ownership integration; retained for the bridge release. */
class_alias(ActivityOwnershipGuard::class, __NAMESPACE__.'\\ActivityOwnershipGuard');
