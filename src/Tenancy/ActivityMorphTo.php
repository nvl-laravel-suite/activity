<?php

declare(strict_types=1);

namespace Nvl\Activity\Tenancy;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

/**
 * Activity-owned MorphTo relation that admits stored types and ownership before hydration.
 *
 * @internal
 *
 * @template TActivity of ActivityLog
 *
 * @extends MorphTo<Model, TActivity>
 */
final class ActivityMorphTo extends MorphTo
{
    /**
     * @param  Builder<Model>  $query
     */
    public function __construct(
        Builder $query,
        ActivityLog $parent,
        string $foreignKey,
        ?string $ownerKey,
        string $type,
        string $relation,
        private readonly ActivityOwnershipGuard $ownership,
        private readonly bool $causer,
        private readonly bool $guarded,
    ) {
        parent::__construct($query, $parent, $foreignKey, $ownerKey, $type, $relation);
    }

    /** {@inheritDoc} */
    #[\Override]
    public function addEagerConstraints(array $models)
    {
        if (! $this->guarded) {
            parent::addEagerConstraints($models);

            return;
        }

        $activities = (new ActivityLog)->newCollection();
        foreach ($models as $activity) {
            $activities->push($activity);
        }
        $this->ownership->synchronizeActivities($activities);

        parent::addEagerConstraints($models);
    }

    /** {@inheritDoc} */
    #[\Override]
    public function getEager()
    {
        if (! $this->guarded) {
            return parent::getEager();
        }

        $models = parent::getEager();

        foreach ($models as $activity) {
            $this->ownership->trustRelation($activity, $this->relationName);
        }

        return $models;
    }

    /** {@inheritDoc} */
    #[\Override]
    public function createModelByType($type)
    {
        if (! $this->guarded) {
            return parent::createModelByType($type);
        }

        $model = $this->ownership->relationModel((string) $type, $this->causer);

        return $model ?? throw new TenantBoundaryViolation('Activity relations require registered canonical storage.');
    }

    /** {@inheritDoc} */
    #[\Override]
    protected function getResultsByType($type)
    {
        if (! $this->guarded) {
            return parent::getResultsByType($type);
        }

        $instance = $this->createModelByType($type);
        $ownerKey = $this->ownerKey ?? $instance->getKeyName();
        $query = $this->ownership->relatedQuery($this->replayMacros($instance->newQuery()));

        if ($this->causer) {
            $query->select($this->ownership->causerColumns($instance));
        }

        $query->mergeConstraintsFrom($this->getQuery())
            ->with($this->eagerLoads($instance))
            ->withCount($this->morphableEagerLoadCounts($instance));

        if (($callback = $this->morphableConstraint($instance)) instanceof Closure) {
            $callback($query);
        }

        $whereIn = $this->whereInMethod($instance, $ownerKey);
        $qualifiedOwnerKey = $instance->qualifyColumn($ownerKey);
        $keys = $this->gatherKeysByType($type, $instance->getKeyType());

        if ($whereIn === 'whereIntegerInRaw') {
            $query->whereIntegerInRaw($qualifiedOwnerKey, $keys);
        } else {
            $query->whereIn($qualifiedOwnerKey, $keys);
        }

        return $query->get();
    }

    /** @return array<array-key, array<array-key, mixed>|(Closure(Relation<*, *, *>): mixed)|string> */
    private function eagerLoads(Model $instance): array
    {
        $configured = $this->getQuery()->getEagerLoads();
        $morphable = $this->morphableEagerLoads[$instance::class] ?? [];
        if (is_array($morphable)) {
            $configured = array_merge($configured, $morphable);
        }

        $loads = [];
        foreach ($configured as $key => $load) {
            if (is_array($load) || is_string($load)) {
                $loads[$key] = $load;
            } elseif ($load instanceof Closure) {
                $loads[$key] = static fn (Relation $relation): mixed => $load($relation);
            }
        }

        return $loads;
    }

    /** @return array<array-key, mixed> */
    private function morphableEagerLoadCounts(Model $instance): array
    {
        $loads = [];
        $configured = $this->morphableEagerLoadCounts[$instance::class] ?? [];
        if (! is_array($configured)) {
            return $loads;
        }
        foreach ($configured as $key => $load) {
            if (is_array($load) || is_string($load) || $load instanceof Closure) {
                $loads[$key] = $load;
            }
        }

        return $loads;
    }

    /** Resolve only a callable per-type eager constraint. */
    private function morphableConstraint(Model $instance): ?Closure
    {
        $constraint = $this->morphableConstraints[$instance::class] ?? null;

        return $constraint instanceof Closure ? $constraint : null;
    }
}
