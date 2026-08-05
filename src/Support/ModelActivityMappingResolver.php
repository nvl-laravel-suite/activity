<?php

declare(strict_types=1);

namespace Nvl\Activity\Support;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Nvl\Activity\Contracts\ActivityMapping;
use Nvl\Activity\Services\MappingRegistry;

/**
 * Bridges activity-aware models to the provider-registered mapping registry.
 *
 * Eloquent traits cannot receive constructor dependencies, so the provider
 * assigns the canonical registry during boot and traits delegate here without
 * resolving dependencies from the service container.
 */
final class ModelActivityMappingResolver
{
    private static ?MappingRegistry $registry = null;

    /**
     * Register the mapping registry for model trait consumers.
     */
    public static function use(MappingRegistry $registry): void
    {
        self::$registry = $registry;
    }

    /**
     * Resolve the registered mapping for a model instance.
     */
    public static function forModel(Model $model): ?ActivityMapping
    {
        if (! self::$registry instanceof MappingRegistry) {
            throw new LogicException('Activity mapping registry has not been registered.');
        }

        return self::$registry->forModel($model::class);
    }
}
