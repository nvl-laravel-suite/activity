<?php

declare(strict_types=1);

namespace Nvl\Activity\Builders;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Nvl\Activity\Data\ActivityIndexFilter;
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
        return $this->orderByDesc('created_at')
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
    public function forIndex(ActivityIndexFilter $filters): static
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
        return $this->where('created_at', '<', $cutoff);
    }

    /**
     * Restrict the query to rows created on or after the supplied cutoff.
     */
    public function newerThanOrEqual(CarbonInterface $cutoff): static
    {
        return $this->where('created_at', '>=', $cutoff);
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
    public function applyIndexFilters(ActivityIndexFilter $filters): static
    {
        if ($filters->search !== null) {
            $term = $filters->search;

            $this->where(function (Builder $query) use ($term): void {
                $query->whereLike('description', "%{$term}%")
                    ->orWhereLike('event', "%{$term}%")
                    ->orWhereLike('log_name', "%{$term}%")
                    ->orWhereLike('subject_type', "%{$term}%");
            });
        }

        if ($filters->event !== null) {
            $this->where('event', $filters->event);
        }

        if ($filters->causerId !== null) {
            $this->where('causer_id', $filters->causerId);
        }

        if ($filters->subjectType !== null) {
            $this->where('subject_type', $filters->subjectType);
        }

        if ($filters->subjectId !== null) {
            $this->where('subject_id', $filters->subjectId);
        }

        if ($filters->createdAtFrom !== null) {
            $this->where('created_at', '>=', $filters->createdAtFrom);
        }

        if ($filters->createdAtTo !== null) {
            $this->where('created_at', '<=', $filters->createdAtTo);
        }

        return $this;
    }
}
