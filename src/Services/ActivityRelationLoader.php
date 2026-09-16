<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Support\ModelKeyIdentifierValidator;
use Nvl\Activity\Tenancy\ActivityOwnershipGuard;
use ReflectionClass;

/**
 * Safely hydrates polymorphic activity relations without breaking on stale model types.
 */
final class ActivityRelationLoader
{
    /**
     * Create the relation loader with historical identifier validation.
     */
    public function __construct(
        private readonly ModelKeyIdentifierValidator $modelKeyIdentifierValidator,
        private readonly ActivityOwnershipGuard $ownership,
    ) {}

    /**
     * Load causer and subject relations in type-grouped batches.
     *
     * @param  EloquentCollection<int, ActivityLog>  $activities
     */
    public function load(EloquentCollection $activities): void
    {
        $this->ownership->attributes();

        if ($this->ownership->enabled()) {
            $this->ownership->synchronizeActivities($activities);
        }

        $this->loadRelation($activities, 'causer', 'causer_type', 'causer_id', includeSoftDeleted: true);
        $this->loadRelation($activities, 'subject', 'subject_type', 'subject_id');
    }

    /**
     * Load one polymorphic relation while treating unavailable historical types as null.
     *
     * @param  EloquentCollection<int, ActivityLog>  $activities
     */
    private function loadRelation(
        EloquentCollection $activities,
        string $relation,
        string $typeColumn,
        string $idColumn,
        bool $includeSoftDeleted = false,
    ): void {
        /** @var array<string, list<ActivityLog>> $groups */
        $groups = [];

        foreach ($activities as $activity) {
            if (! $this->ownership->enabled() && $activity->relationLoaded($relation)) {
                continue;
            }

            $storedType = $activity->getAttribute($typeColumn);

            if (! is_string($storedType) || trim($storedType) === '') {
                $activity->setRelation($relation, null);

                continue;
            }

            $groups[$storedType][] = $activity;
        }

        foreach ($groups as $storedType => $group) {
            $relatedModel = $this->resolveLoadableModel($storedType, $relation === 'causer');

            if (! $relatedModel instanceof Model) {
                foreach ($group as $activity) {
                    $activity->setRelation($relation, null);
                }

                continue;
            }

            $identifiers = array_map(
                static fn (ActivityLog $activity): mixed => $activity->getAttribute($idColumn),
                $group,
            );
            $normalizedIdentifiers = $this->modelKeyIdentifierValidator->normalizedIdentifiers(
                $relatedModel,
                $identifiers,
            );

            /** @var list<array{activity: ActivityLog, identifier: int|string}> $loadableActivities */
            $loadableActivities = [];

            foreach ($group as $index => $activity) {
                $normalizedIdentifier = $normalizedIdentifiers[$index] ?? null;
                if ($normalizedIdentifier === null) {
                    $activity->setRelation($relation, null);

                    continue;
                }

                $loadableActivities[] = [
                    'activity' => $activity,
                    'identifier' => $normalizedIdentifier,
                ];
            }

            if ($loadableActivities === []) {
                continue;
            }

            $relatedModels = $this->relatedModelsByIdentifier(
                $relatedModel,
                array_column($loadableActivities, 'identifier'),
                $includeSoftDeleted,
                $relation === 'causer',
            );

            foreach ($loadableActivities as $loadableActivity) {
                $loadableActivity['activity']->setRelation(
                    $relation,
                    $relatedModels[(string) $loadableActivity['identifier']] ?? null,
                );
            }
        }

        if ($this->ownership->enabled()) {
            foreach ($activities as $activity) {
                $this->ownership->trustRelation($activity, $relation);
            }
        }
    }

    /**
     * Load related models on their own declared or default connection.
     *
     * Eloquent propagates the parent model connection to a morph target whose
     * model has no explicit connection. Activity storage may intentionally use
     * another connection, so relation queries must originate from the related
     * model instead of the Activity model's MorphTo relation.
     *
     * @param  list<string|int>  $identifiers
     * @return array<string, Model>
     */
    private function relatedModelsByIdentifier(
        Model $relatedModel,
        array $identifiers,
        bool $includeSoftDeleted,
        bool $causer,
    ): array {
        $query = $relatedModel->newQuery()->whereKey(array_values(array_unique(
            $identifiers,
            SORT_REGULAR,
        )));

        if ($this->ownership->enabled()) {
            $query = $this->ownership->relatedQuery($query);

            if ($causer) {
                $query->select($this->ownership->causerColumns($relatedModel));
            }
        }

        if ($includeSoftDeleted && in_array(SoftDeletes::class, class_uses_recursive($relatedModel), true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        $models = [];

        foreach ($query->get() as $model) {
            $identifier = $model->getKey();

            if (is_string($identifier) || is_int($identifier)) {
                $models[(string) $identifier] = $model;
            }
        }

        return $models;
    }

    /**
     * Determine whether a stored morph type resolves to an available Eloquent table.
     */
    private function resolveLoadableModel(string $storedType, bool $causer): ?Model
    {
        if ($this->ownership->enabled()) {
            return $this->ownership->relationModel($storedType, $causer);
        }

        $modelClass = Relation::getMorphedModel($storedType) ?? $storedType;

        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        /** @var class-string<Model> $modelClass */
        $modelReflection = new ReflectionClass($modelClass);
        $constructor = $modelReflection->getConstructor();

        if (! $modelReflection->isInstantiable()
            || ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0)) {
            return null;
        }

        $model = new $modelClass;

        $tableExists = $model->getConnection()
            ->getSchemaBuilder()
            ->hasTable($model->getTable());

        return $tableExists ? $model : null;
    }
}
