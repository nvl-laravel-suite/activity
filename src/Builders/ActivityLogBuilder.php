<?php

declare(strict_types=1);

namespace Nvl\Activity\Builders;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Nvl\Activity\Enums\ActivityImportance;
use Nvl\Activity\Enums\ActivitySource;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Support\ActivityPurgeCriteria;

/**
 * Dedicated query builder for Activity read concerns.
 *
 * @extends Builder<ActivityLog>
 */
final class ActivityLogBuilder extends Builder
{
    /**
     * Apply the default newest-first ordering for activity reads.
     */
    public function newestFirst(): static
    {
        return $this->orderByRaw('CASE WHEN created_at IS NULL THEN 1 ELSE 0 END')->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Apply the default oldest-first ordering for purge and maintenance flows.
     */
    public function oldestFirst(): static
    {
        return $this->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * Apply the Activity index listing query profile.
     */
    /** @param array{search?:string|null,event?:string|null,events?:list<string>,causer_id?:string|int|null,subject_type?:string|null,subject_id?:string|int|null,created_at_from?:\DateTimeInterface|string|null,created_at_to?:\DateTimeInterface|string|null} $filters */
    public function forIndex(array $filters): static
    {
        return $this->applyIndexFilters($filters);
    }

    /**
     * Restrict the query to activity rows for the given subject.
     *
     * @param  string|int|list<string|int>  $subjectId
     */
    public function forSubject(string $subjectType, string|int|array $subjectId): static
    {
        $this->where('subject_type', $subjectType);

        if (is_array($subjectId)) {
            $this->whereIn('subject_id', $subjectId);
        } else {
            $this->where('subject_id', $subjectId);
        }

        return $this;
    }

    /**
     * Restrict the query to activity rows created within the given date range.
     */
    public function withinDateRange(CarbonInterface $startDate, CarbonInterface $endDate): static
    {
        return $this->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Restrict the query to rows older than the supplied cutoff.
     */
    public function olderThan(CarbonInterface $cutoff): static
    {
        return $this->where('created_at', '<', $cutoff->format('Y-m-d H:i:s.u'));
    }

    /**
     * Restrict the query to rows created on or after the supplied cutoff.
     */
    public function newerThanOrEqual(CarbonInterface $cutoff): static
    {
        return $this->where('created_at', '>=', $cutoff->format('Y-m-d H:i:s.u'));
    }

    /**
     * Restrict the query to system-originated rows.
     */
    public function whereSystemGenerated(): static
    {
        return $this->where(function (Builder $query): void {
            $query
                ->whereJsonContains('properties->source', ActivitySource::System->value)
                ->orWhere(function (Builder $legacyQuery): void {
                    $legacyQuery
                        ->whereNull('causer_id')
                        ->whereNull('properties->source')
                        ->whereNull('properties->actor_id');
                });
        });
    }

    /**
     * Exclude evidence carrying the package's protected importance classification.
     */
    public function withoutImportant(): static
    {
        return $this->where(function (Builder $query): void {
            $query->whereNull('properties->importance')
                ->orWhereJsonDoesntContain(
                    'properties->importance',
                    ActivityImportance::Important->value,
                );
        });
    }

    /**
     * Restrict the query to one or more event names.
     *
     * @param  list<string>  $events
     */
    public function whereEvents(array $events): static
    {
        if ($events !== []) {
            $this->whereIn('event', $events);
        }

        return $this;
    }

    /**
     * Restrict the query to one or more log names.
     *
     * @param  list<string>  $logNames
     */
    public function whereLogNames(array $logNames): static
    {
        if ($logNames !== []) {
            $this->whereIn('log_name', $logNames);
        }

        return $this;
    }

    /**
     * Restrict the query to one or more subject types.
     *
     * @param  list<string>  $subjectTypes
     */
    public function whereSubjectTypes(array $subjectTypes): static
    {
        if ($subjectTypes !== []) {
            $this->whereIn('subject_type', $subjectTypes);
        }

        return $this;
    }

    /**
     * Restrict the query to one or more subject identifiers.
     *
     * @param  list<string>  $subjectIds
     */
    public function whereSubjectIds(array $subjectIds): static
    {
        if ($subjectIds !== []) {
            $this->whereIn('subject_id', $subjectIds);
        }

        return $this;
    }

    /**
     * Restrict the query to one or more causer types.
     *
     * @param  list<string>  $causerTypes
     */
    public function whereCauserTypes(array $causerTypes): static
    {
        if ($causerTypes !== []) {
            $this->whereIn('causer_type', $causerTypes);
        }

        return $this;
    }

    /**
     * Restrict the query to one or more causer identifiers.
     *
     * @param  list<string>  $causerIds
     */
    public function whereCauserIds(array $causerIds): static
    {
        if ($causerIds !== []) {
            $this->whereIn('causer_id', $causerIds);
        }

        return $this;
    }

    /**
     * Apply shared purge criteria for maintenance flows.
     */
    public function applyPurgeCriteria(ActivityPurgeCriteria $criteria): static
    {
        $this->olderThan($criteria->cutoff());

        $after = $criteria->afterCutoff();
        if ($after !== null) {
            $this->newerThanOrEqual($after);
        }

        if ($criteria->systemOnly) {
            $this->whereSystemGenerated();
        }

        if (! $criteria->includeImportant) {
            $this->withoutImportant();
        }

        return $this
            ->whereEvents($criteria->events)
            ->whereLogNames($criteria->logNames)
            ->whereSubjectTypes($criteria->subjectTypes)
            ->whereSubjectIds($criteria->subjectIds)
            ->whereCauserTypes($criteria->causerTypes)
            ->whereCauserIds($criteria->causerIds);
    }

    /**
     * Limit the query to the requested number of newest rows.
     */
    public function limitLatest(int $limit): static
    {
        $this->limit(max(0, $limit));

        return $this;
    }

    /**
     * Apply index filters without exposing HTTP concerns in callers.
     */
    /** @param array{search?:string|null,event?:string|null,events?:list<string>,causer_id?:string|int|null,subject_type?:string|null,subject_id?:string|int|null,created_at_from?:\DateTimeInterface|string|null,created_at_to?:\DateTimeInterface|string|null} $filters */
    public function applyIndexFilters(array $filters): static
    {
        if (($filters['search'] ?? null) !== null) {
            $term = $filters['search'];

            $this->where(function (Builder $query) use ($term): void {
                $query->whereLike('description', "%{$term}%")
                    ->orWhereLike('event', "%{$term}%")
                    ->orWhereLike('log_name', "%{$term}%")
                    ->orWhereLike('subject_type', "%{$term}%");
            });
        }

        if (($filters['events'] ?? []) !== []) {
            $this->whereEvents($filters['events']);
        } elseif (($filters['event'] ?? null) !== null) {
            $this->where('event', $filters['event']);
        }

        if (($filters['causer_id'] ?? null) !== null) {
            $this->where('causer_id', $filters['causer_id']);
        }

        if (($filters['subject_type'] ?? null) !== null) {
            $this->where('subject_type', $filters['subject_type']);
        }

        if (($filters['subject_id'] ?? null) !== null) {
            $this->where('subject_id', $filters['subject_id']);
        }

        if (($filters['created_at_from'] ?? null) !== null) {
            $this->where('created_at', '>=', $filters['created_at_from'] instanceof \DateTimeInterface ? $filters['created_at_from']->format('Y-m-d H:i:s.u') : $filters['created_at_from']);
        }

        if (($filters['created_at_to'] ?? null) !== null) {
            $this->where('created_at', '<=', $filters['created_at_to'] instanceof \DateTimeInterface ? $filters['created_at_to']->format('Y-m-d H:i:s.u') : $filters['created_at_to']);
        }

        return $this;
    }
}
