<?php

declare(strict_types=1);

namespace Nvl\Activity\Ownership;

use Nvl\Activity\Services\ActivityModelRegistry;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

/**
 * Declares independent audit facts without deriving ownership from an optional subject.
 *
 * @internal
 */
final readonly class ActivityResourceRegistrar
{
    /** Compile the explicit deployment classification before the graph freezes. */
    public function register(OwnershipResourceRegistry $resources, bool $enabled): void
    {
        $resources->register(new OwnershipResourceDefinition('activity.events', 'activity', ActivityModelRegistry::modelClass(), $enabled ? OwnershipDefinition::captured() : OwnershipDefinition::unscoped(), allowsCentral: $enabled));
    }
}
