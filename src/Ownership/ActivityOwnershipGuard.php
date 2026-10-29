<?php

declare(strict_types=1);

namespace Nvl\Activity\Ownership;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityModelRegistry;
use Nvl\Support\Exceptions\BusinessException;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use WeakMap;

/** Guards manual and automatic capture and resolves only canonical permitted relation storage. */
readonly class ActivityOwnershipGuard
{
    /** @var WeakMap<Model, array{ownership: array<string, int|string|null>, admission: string}> */
    private WeakMap $deletingSubjects;

    /**
     * @var WeakMap<Model, array<string, array{
     *     ownership: array<string, int|string|null>,
     *     admission: string,
     *     class: class-string<Model>,
     *     table: string,
     *     connection: int,
     *     key: string,
     *     exists: bool,
     *     original: string|int|null,
     *     current: string|int|null
     * }>>
     */
    private WeakMap $trustedRelations;

    /** Share Foundation's current-scope boundary across every Activity entry point. */
    public function __construct(private OwnershipBoundary $boundary, private OwnershipResourceRegistry $resources, private Repository $configuration)
    {
        $this->deletingSubjects = new WeakMap;
        $this->trustedRelations = new WeakMap;
    }

    /** Report whether subject ownership and the safe global identity projection are required. */
    public function enabled(): bool
    {
        return $this->attributes() !== [];
    }

    /** @return array<string, int|string|null> */
    public function attributes(): array
    {
        return $this->boundary->attributes('activity.events');
    }

    /** Preserve the single enforcing authority's exception presentation during the bridge release. */
    public function violation(string $message): BusinessException
    {
        return new OwnershipViolation($message);
    }

    /** Stamp every newly persisted native activity, including automatic model logging. */
    public function stamp(ActivityLog $activity): void
    {
        $ownership = $this->attributes();
        if ($ownership === []) {
            return;
        }
        if ($activity->relationLoaded('subject') && ($subject = $activity->getRelation('subject')) instanceof Model) {
            $capturedDeletion = $this->deletingSubjects[$subject] ?? null;
            if ($activity->getAttribute('event') === 'deleted'
                && $capturedDeletion === ['ownership' => $ownership, 'admission' => $this->boundary->key('activity.events', 'relations')]) {
                unset($this->deletingSubjects[$subject]);
            } else {
                $this->assertSubject($subject);
            }
        } elseif (is_string($activity->getAttribute('subject_type')) && $activity->getAttribute('subject_type') !== '') {
            $this->forgetTrustedRelation($activity, 'subject');
        }
        if ($activity->relationLoaded('causer') && ($causer = $activity->getRelation('causer')) instanceof Model) {
            $canonicalCauser = $this->canonicalCauser($causer);
            $activity->forceFill([
                'causer_type' => $canonicalCauser->getMorphClass(),
                'causer_id' => $canonicalCauser->getKey(),
            ]);
            $activity->setRelation('causer', $canonicalCauser);
            $this->trustRelation($activity, 'causer');
        }
        foreach ($ownership as $key => $value) {
            if (array_key_exists($key, $activity->getAttributes()) && $activity->getAttribute($key) !== $value) {
                throw $this->violation('Activity capture ownership differs from the current scope.');
            }
        }
        $activity->forceFill($ownership);
    }

    /** Refuse ownership transfer of an existing immutable audit fact. */
    public function updating(ActivityLog $activity): void
    {
        $this->boundary->assertRecord($activity, 'activity.events');
        if ($this->enabled() && $activity->isDirty(['owner_scope_key', 'owner_reference'])) {
            throw $this->violation('Activity ownership is immutable.');
        }
    }

    /** Consume the exact pre-deletion owner receipt in the same admitted scope. */
    public function consumeDeletingSubject(Model $subject): bool
    {
        $captured = $this->deletingSubjects[$subject] ?? null;
        if ($captured === null) {
            return false;
        }
        unset($this->deletingSubjects[$subject]);
        if ($captured !== ['ownership' => $this->attributes(), 'admission' => $this->boundary->key('activity.events', 'relations')]) {
            throw $this->violation('Activity deletion capture crossed an ownership boundary.');
        }

        return true;
    }

    /**
     * Reload supplied Activity identities through one canonical partitioned query.
     *
     * @param  EloquentCollection<int, ActivityLog>  $activities
     * @return EloquentCollection<int, ActivityLog>
     */
    public function canonicalActivities(EloquentCollection $activities): EloquentCollection
    {
        $this->attributes();

        if (! $this->enabled()) {
            return $activities;
        }

        $canonicalModel = ActivityModelRegistry::newInstance();
        $identifiers = [];

        foreach ($activities as $activity) {
            $identifier = $activity->getRawOriginal($activity->getKeyName());
            if (! $this->isCanonicalActivityModel($activity)
                || $activity->getTable() !== $canonicalModel->getTable()
                || $activity->getConnection() !== $canonicalModel->getConnection()
                || ! $activity->exists
                || (! is_string($identifier) && ! is_int($identifier))) {
                throw $this->violation('Activity hydration requires canonical persisted identities.');
            }

            $identifiers[(string) $identifier] = $identifier;
        }

        if ($identifiers === []) {
            return new EloquentCollection;
        }

        /** @var EloquentCollection<int, ActivityLog> $canonical */
        $canonical = ActivityModelRegistry::query()->whereKey(array_values($identifiers))->get();
        if ($canonical->keyBy(fn (ActivityLog $activity): string => $this->identifier($activity->getKey()))->count() !== count($identifiers)) {
            throw $this->violation('Activity rows require canonical ownership in the current scope.');
        }

        return $canonical;
    }

    /**
     * Replace supplied Activity state with canonical rows from the current partition.
     *
     * @param  EloquentCollection<int, ActivityLog>  $activities
     */
    public function synchronizeActivities(EloquentCollection $activities): void
    {
        $ownership = $this->attributes();
        $preservedRelations = [];

        foreach ($activities as $activity) {
            foreach (['causer', 'subject'] as $relation) {
                if ($activity->relationLoaded($relation)
                    && $this->relationTrustedForOwnership($activity, $relation, $ownership)) {
                    $preservedRelations[spl_object_id($activity)][$relation] = $activity->getRelation($relation);
                }
            }
        }

        $canonicalById = $this->canonicalActivities($activities)->keyBy(
            fn (ActivityLog $activity): string => $this->identifier($activity->getKey()),
        );

        foreach ($activities as $activity) {
            $canonical = $canonicalById->get($this->identifier(
                $activity->getRawOriginal($activity->getKeyName()),
            ));

            if (! $canonical instanceof ActivityLog) {
                continue;
            }

            $activity->setRawAttributes($canonical->getAttributes(), true);
            $activity->unsetRelation('causer');
            $activity->unsetRelation('subject');
            $this->forgetTrustedRelation($activity, 'causer');
            $this->forgetTrustedRelation($activity, 'subject');

            foreach ($preservedRelations[spl_object_id($activity)] ?? [] as $relation => $value) {
                $activity->setRelation($relation, $value);
                $this->trustRelationForOwnership($activity, $relation, $ownership);
            }
        }
    }

    /** Replace one supplied Activity instance with its canonical current-partition row. */
    public function synchronizeActivity(ActivityLog $activity): void
    {
        $this->synchronizeActivities(new EloquentCollection([$activity]));
    }

    /** Mark a package-loaded relation as admitted for direct model access. */
    public function trustRelation(ActivityLog $activity, string $relation): void
    {
        $this->trustRelationForOwnership($activity, $relation, $this->attributes());
    }

    /** Invalidate caller-supplied relation state. */
    public function forgetTrustedRelation(ActivityLog $activity, string $relation): void
    {
        $trusted = $this->trustedRelations[$activity] ?? [];
        unset($trusted[$relation]);
        $this->trustedRelations[$activity] = $trusted;
    }

    /** Report whether a relation was loaded through an admitted package path. */
    public function relationTrusted(ActivityLog $activity, string $relation): bool
    {
        return $this->relationTrustedForOwnership($activity, $relation, $this->attributes());
    }

    /**
     * Mark one relation as admitted for an exact Activity identity and ownership context.
     *
     * @param  array<string, int|string|null>  $ownership
     */
    private function trustRelationForOwnership(ActivityLog $activity, string $relation, array $ownership): void
    {
        $identity = $this->relationTrustIdentity($activity);
        if ($identity === null) {
            $this->forgetTrustedRelation($activity, $relation);

            return;
        }

        $trusted = $this->trustedRelations[$activity] ?? [];
        $trusted[$relation] = ['ownership' => $ownership, ...$identity];
        $this->trustedRelations[$activity] = $trusted;
    }

    /**
     * Confirm relation admission still matches the active context and exact Activity identity.
     *
     * @param  array<string, int|string|null>  $ownership
     */
    private function relationTrustedForOwnership(ActivityLog $activity, string $relation, array $ownership): bool
    {
        $identity = $this->relationTrustIdentity($activity);

        return $identity !== null
            && (($this->trustedRelations[$activity] ?? [])[$relation] ?? null) === ['ownership' => $ownership, ...$identity];
    }

    /**
     * Describe the exact Activity storage and key admitted with a relation.
     *
     * @return array{
     *     admission: string,
     *     class: class-string<Model>,
     *     table: string,
     *     connection: int,
     *     key: string,
     *     exists: bool,
     *     original: string|int|null,
     *     current: string|int|null
     * }|null
     */
    private function relationTrustIdentity(ActivityLog $activity): ?array
    {
        $keyName = $activity->getKeyName();
        $original = $activity->getRawOriginal($keyName);
        $current = $activity->getKey();

        if (($original !== null && ! is_string($original) && ! is_int($original))
            || ($current !== null && ! is_string($current) && ! is_int($current))
            || ($activity->exists && ($original === null || $current === null || $original !== $current))) {
            return null;
        }

        return [
            'admission' => $this->boundary->key('activity.events', 'relations'),
            'class' => $activity::class,
            'table' => $activity->getTable(),
            'connection' => spl_object_id($activity->getConnection()),
            'key' => $keyName,
            'exists' => $activity->exists,
            'original' => $original,
            'current' => $current,
        ];
    }

    /** Preserve the runtime exact-class check hidden by the generic collection contract. */
    private function isCanonicalActivityModel(Model $activity): bool
    {
        return $activity::class === ActivityModelRegistry::modelClass();
    }

    /** Normalize a validated model key without accepting unsupported identifier types. */
    private function identifier(mixed $identifier): string
    {
        if (! is_string($identifier) && ! is_int($identifier)) {
            throw $this->violation('Activity rows require canonical scalar identities.');
        }

        return (string) $identifier;
    }

    /** Capture a subject's canonical ownership before a hard deletion removes its row. */
    public function captureDeletingSubject(Model $subject): void
    {
        $this->assertSubject($subject);
        $this->deletingSubjects[$subject] = ['ownership' => $this->attributes(), 'admission' => $this->boundary->key('activity.events', 'relations')];
    }

    /** Validate caller-supplied subject identities against persisted ownership. */
    public function assertSubject(Model $subject): void
    {
        $this->attributes();
        if ($this->enabled()) {
            $definition = $this->definition($subject::class)
                ?? throw $this->violation('Activity subjects require registered canonical ownership.');
            $canonical = new $definition->model;
            if ($subject->getTable() !== $canonical->getTable()
                || $subject->getConnection() !== $canonical->getConnection()
                || $subject->getKeyName() !== $canonical->getKeyName()
                || ! $subject->exists) {
                throw $this->violation('Activity subjects require canonical registered storage.');
            }
            $identifier = $subject->getKey();
            $originalIdentifier = $subject->getRawOriginal($subject->getKeyName());
            if ((! is_string($identifier) && ! is_int($identifier))
                || ($originalIdentifier !== null && $originalIdentifier !== $identifier)
                || ($originalIdentifier === null && ! $subject->wasRecentlyCreated)) {
                throw $this->violation('Activity subjects require canonical persisted identities.');
            }
            $canonical = $this->boundary->query($canonical->newQuery(), $definition->key)->whereKey($originalIdentifier ?? $identifier)->first();
            if (! $canonical instanceof Model) {
                throw $this->violation('Activity subjects require a persisted canonical row in the current partition.');
            }
            $this->boundary->assertRecord($canonical, $definition->key);
        }
    }

    /** Reload a supplied causer through its canonical registered or global identity. */
    public function canonicalCauser(Model $causer): Model
    {
        $this->attributes();
        if (! $this->enabled()) {
            return $causer;
        }

        $definition = $this->definition($causer::class);
        if ($definition !== null) {
            $canonicalClass = $definition->model;
        } else {
            $globalClass = $this->globalCauserClass();
            if (! is_string($globalClass) || $causer::class !== $globalClass) {
                throw $this->violation('Activity causers require registered ownership or the configured identity presenter.');
            }
            $canonicalClass = $globalClass;
        }
        $canonical = new $canonicalClass;
        if ($causer::class !== $canonical::class
            || $causer->getTable() !== $canonical->getTable()
            || $causer->getConnection() !== $canonical->getConnection()
            || $causer->getKeyName() !== $canonical->getKeyName()
            || ! $causer->exists) {
            throw $this->violation('Activity causers require canonical declared storage.');
        }

        $identifier = $causer->getKey();
        $originalIdentifier = $causer->getRawOriginal($causer->getKeyName());
        if ((! is_string($identifier) && ! is_int($identifier))
            || ($originalIdentifier !== null && $originalIdentifier !== $identifier)
            || ($originalIdentifier === null && ! $causer->wasRecentlyCreated)) {
            throw $this->violation('Activity causers require canonical persisted identities.');
        }

        $query = $canonical->newQuery()->whereKey($originalIdentifier ?? $identifier);
        if ($definition !== null) {
            $query = $this->boundary->query($query, $definition->key);
        }

        $canonicalCauser = $query->select($this->causerColumns($canonical))->first();
        if (! $canonicalCauser instanceof Model) {
            throw $this->violation('Activity causers require a persisted identity in the permitted scope.');
        }

        return $canonicalCauser;
    }

    /** Report whether a concrete model has registered canonical tenant ownership. */
    public function registered(Model $model): bool
    {
        return $this->definition($model::class) !== null;
    }

    /** Resolve only explicitly registered classes, or the single configured global causer class. */
    public function relationModel(string $storedType, bool $causer): ?Model
    {
        $class = Relation::getMorphedModel($storedType) ?? $storedType;
        $definition = $this->definition($class);
        if ($definition !== null) {
            return new $definition->model;
        }
        $global = $this->globalCauserClass();

        return $causer && $class === $global && is_a($global, Model::class, true) ? new $global : null;
    }

    /**
     * Apply canonical ownership even when a registered model is configured as the global presenter.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function relatedQuery(Builder $query): Builder
    {
        $definition = $this->definition($query->getModel()::class);
        if ($definition !== null) {
            return $this->boundary->query($query, $definition->key);
        }
        if ($query->getModel()::class !== $this->globalCauserClass()) {
            throw $this->violation('Unregistered Activity relation storage.');
        }

        return $query;
    }

    /** @return list<string> */
    public function causerColumns(Model $model): array
    {
        $columns = $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable());

        return array_values(array_intersect([$model->getKeyName(), 'name', 'email', 'first_name', 'last_name'], $columns));
    }

    /** Look up immutable class names without instantiating an untrusted stored type. */
    private function definition(string $class): ?OwnershipResourceDefinition
    {
        foreach ($this->resources->all() as $definition) {
            if ($definition->model === $class) {
                return $definition;
            }
        }

        return null;
    }

    /** Resolve the existing explicit causer display seam without loading Auth. */
    private function globalCauserClass(): ?string
    {
        $class = $this->configuration->get('nvl-activity.causer_suggestions.model')
            ?: $this->configuration->get('auth.providers.users.model');

        return is_string($class) ? $class : null;
    }
}
