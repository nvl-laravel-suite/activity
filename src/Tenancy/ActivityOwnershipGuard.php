<?php

declare(strict_types=1);

namespace Nvl\Activity\Tenancy;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;
use WeakMap;

/** Guards manual and automatic capture and resolves only canonical permitted relation storage. */
final readonly class ActivityOwnershipGuard
{
    /** @var WeakMap<Model, array{tenant_id?: string|null, ownership_key?: string}> */
    private WeakMap $deletingSubjects;

    /** Share Foundation's current-scope boundary across every Activity entry point. */
    public function __construct(private TenantBoundary $boundary, private TenantResourceRegistry $resources, private Repository $configuration)
    {
        $this->deletingSubjects = new WeakMap;
    }

    /** Report whether subject ownership and the safe global identity projection are required. */
    public function enabled(): bool
    {
        return $this->configuration->get('tenancy.enabled') === true;
    }

    /** @return array{tenant_id?: string|null, ownership_key?: string} */
    public function attributes(): array
    {
        return $this->boundary->attributes('activity.events');
    }

    /** Stamp every newly persisted Spatie activity, including automatic model logging. */
    public function stamp(ActivityLog $activity): void
    {
        $ownership = $this->attributes();
        if ($ownership === []) {
            return;
        }
        if ($activity->relationLoaded('subject') && ($subject = $activity->getRelation('subject')) instanceof Model) {
            $capturedDeletion = $this->deletingSubjects[$subject] ?? null;
            if ($activity->getAttribute('event') === 'deleted' && $capturedDeletion === $ownership) {
                unset($this->deletingSubjects[$subject]);
            } else {
                $this->assertSubject($subject);
            }
        }
        if ($activity->relationLoaded('causer') && ($causer = $activity->getRelation('causer')) instanceof Model) {
            $definition = $this->definition($causer::class);
            if ($definition !== null) {
                $this->boundary->assertRecord($causer, $definition->key);
            } elseif ($causer::class !== $this->globalCauserClass()) {
                throw new TenantBoundaryViolation('Activity causers require registered ownership or the configured identity presenter.');
            }
        }
        foreach ($ownership as $key => $value) {
            if (array_key_exists($key, $activity->getAttributes()) && $activity->getAttribute($key) !== $value) {
                throw new TenantBoundaryViolation('Activity capture ownership differs from the current scope.');
            }
        }
        $activity->forceFill($ownership);
    }

    /** Refuse ownership transfer of an existing immutable audit fact. */
    public function updating(ActivityLog $activity): void
    {
        $this->boundary->assertRecord($activity, 'activity.events');
        if ($this->enabled() && $activity->isDirty(['tenant_id', 'ownership_key'])) {
            throw new TenantBoundaryViolation('Activity ownership is immutable.');
        }
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

        $canonicalModel = new ActivityLog;
        $identifiers = [];

        foreach ($activities as $activity) {
            $identifier = $activity->getRawOriginal($activity->getKeyName());
            if (! $this->isCanonicalActivityModel($activity)
                || $activity->getTable() !== $canonicalModel->getTable()
                || $activity->getConnection() !== $canonicalModel->getConnection()
                || ! $activity->exists
                || (! is_string($identifier) && ! is_int($identifier))) {
                throw new TenantBoundaryViolation('Activity hydration requires canonical persisted identities.');
            }

            $identifiers[(string) $identifier] = $identifier;
        }

        if ($identifiers === []) {
            return new EloquentCollection;
        }

        /** @var EloquentCollection<int, ActivityLog> $canonical */
        $canonical = ActivityLog::query()->whereKey(array_values($identifiers))->get();
        if ($canonical->keyBy(fn (ActivityLog $activity): string => $this->identifier($activity->getKey()))->count() !== count($identifiers)) {
            throw new TenantBoundaryViolation('Activity rows require canonical ownership in the current scope.');
        }

        return $canonical;
    }

    /** Preserve the runtime exact-class check hidden by the generic collection contract. */
    private function isCanonicalActivityModel(Model $activity): bool
    {
        return $activity::class === ActivityLog::class;
    }

    /** Normalize a validated model key without accepting unsupported identifier types. */
    private function identifier(mixed $identifier): string
    {
        if (! is_string($identifier) && ! is_int($identifier)) {
            throw new TenantBoundaryViolation('Activity rows require canonical scalar identities.');
        }

        return (string) $identifier;
    }

    /** Capture a subject's canonical ownership before a hard deletion removes its row. */
    public function captureDeletingSubject(Model $subject): void
    {
        $this->assertSubject($subject);
        $this->deletingSubjects[$subject] = $this->attributes();
    }

    /** Validate caller-supplied subject identities against persisted ownership. */
    public function assertSubject(Model $subject): void
    {
        $this->attributes();
        if ($this->enabled()) {
            $definition = $this->definition($subject::class)
                ?? throw new TenantBoundaryViolation('Activity subjects require registered canonical ownership.');
            $canonical = new $definition->model;
            if ($subject->getTable() !== $canonical->getTable()
                || $subject->getConnection() !== $canonical->getConnection()
                || $subject->getKeyName() !== $canonical->getKeyName()
                || ! $subject->exists) {
                throw new TenantBoundaryViolation('Activity subjects require canonical registered storage.');
            }
            $identifier = $subject->getKey();
            $originalIdentifier = $subject->getRawOriginal($subject->getKeyName());
            if ((! is_string($identifier) && ! is_int($identifier))
                || ($originalIdentifier !== null && $originalIdentifier !== $identifier)
                || ($originalIdentifier === null && ! $subject->wasRecentlyCreated)) {
                throw new TenantBoundaryViolation('Activity subjects require canonical persisted identities.');
            }
            $canonical->setRawAttributes([$canonical->getKeyName() => $originalIdentifier ?? $identifier], true);
            $canonical->exists = true;
            $this->boundary->assertRecord($canonical, $definition->key);
        }
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
            throw new TenantBoundaryViolation('Unregistered Activity relation storage.');
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
    private function definition(string $class): ?TenantResourceDefinition
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
        $class = $this->configuration->get('activity.causer_suggestions.model')
            ?: $this->configuration->get('auth.providers.users.model');

        return is_string($class) ? $class : null;
    }
}
