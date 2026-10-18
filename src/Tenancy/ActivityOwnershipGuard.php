<?php

declare(strict_types=1);

namespace Nvl\Activity\Tenancy;

use Nvl\Activity\Ownership\ActivityOwnershipGuard as OwnershipGuard;

/** @deprecated Use the neutral ownership integration; retained for the bridge release. */
final readonly class ActivityOwnershipGuard extends OwnershipGuard {}
