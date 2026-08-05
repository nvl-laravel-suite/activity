<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Illuminate\Database\Eloquent\Model;
use Nvl\Activity\Contracts\ActivityMapping;
use Nvl\Activity\Exceptions\ActivityConfigurationException;

/**
 * Collects registered ActivityMapping implementations for model-aware resolution.
 */
final class MappingRegistry
{
    /** @var array<class-string, ActivityMapping> */
    private array $mappings = [];

    /**
     * Register an ActivityMapping implementation.
     *
     * @param  ActivityMapping  $mapping  Mapping to register
     */
    public function register(ActivityMapping $mapping): void
    {
        $modelClass = $mapping->modelClass();

        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            throw ActivityConfigurationException::invalidMappingModel($mapping::class, $modelClass);
        }

        if (trim($mapping->logName()) === '') {
            throw ActivityConfigurationException::emptyMappingLogName($mapping::class);
        }

        $registeredMapping = $this->mappings[$modelClass] ?? null;

        if ($registeredMapping === $mapping) {
            return;
        }

        if ($registeredMapping !== null) {
            throw ActivityConfigurationException::duplicateMapping($mapping::class, $modelClass);
        }

        $this->mappings[$modelClass] = $mapping;
    }

    /**
     * Get the mapping for a model class, if registered.
     *
     * @param  class-string  $modelClass  Fully qualified model class name
     * @return ActivityMapping|null Mapping or null
     */
    public function forModel(string $modelClass): ?ActivityMapping
    {
        return $this->mappings[$modelClass] ?? null;
    }

    /**
     * Check if a mapping is registered for a model class.
     *
     * @param  class-string  $modelClass  Fully qualified model class name
     * @return bool Whether a mapping exists
     */
    public function has(string $modelClass): bool
    {
        return isset($this->mappings[$modelClass]);
    }

    /**
     * Get all registered mappings.
     *
     * @return array<class-string, ActivityMapping>
     */
    public function all(): array
    {
        return $this->mappings;
    }
}
