<?php

declare(strict_types=1);

namespace Nvl\Activity\Tenancy;

use Nvl\Activity\Ownership\ActivityMorphTo;

/** @deprecated Use the neutral ownership integration; retained for the bridge release. */
class_alias(ActivityMorphTo::class, __NAMESPACE__.'\\ActivityMorphTo');
