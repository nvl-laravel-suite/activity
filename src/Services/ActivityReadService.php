<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;
use Nvl\Activity\Builders\ActivityLogBuilder;
use Nvl\Activity\Data\ActivityIndexFilter;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Support\ActivitySubjectReference;

/**
 * Use-case oriented read service for Activity log retrieval.
 */
final class ActivityReadService
{
    /**
     * Retrieve newest activity rows for the global dashboard feed.
     *
     * @return EloquentCollection<int, ActivityLog>
     */
    public function latest(int $limit = 12): EloquentCollection
    {
        return ActivityLog::forDisplay()
            ->limitLatest($limit)
            ->get();
    }

    /**
     * Build the paginated Activity index listing.
     *
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    public function paginateIndex(ActivityIndexFilter $filters): LengthAwarePaginator
    {
        return ActivityLog::forDisplay()
            ->forIndex($filters)
            ->paginate($filters->perPage);
    }

    /**
     * Retrieve newest activity rows for a concrete subject model.
     *
     * @return EloquentCollection<int, ActivityLog>
     */
    public function forSubject(Model $subject, ?int $limit = 100): EloquentCollection
    {
        $subjectId = $this->modelIdentifier($subject);
        if ($subjectId === null) {
            return new EloquentCollection;
        }

        return $this->forSubjectKey(
            $subject->getMorphClass(),
            $subjectId,
            $limit,
        );
    }

    /**
     * Retrieve newest activity rows for the given subject key.
     *
     * @return EloquentCollection<int, ActivityLog>
     */
    public function forSubjectKey(
        string $subjectType,
        string|int $subjectId,
        ?int $limit = 100,
    ): EloquentCollection {
        $query = $this->subjectQuery($subjectType, $subjectId);

        if ($limit !== null) {
            $query->limitLatest($limit);
        }

        return $query->get();
    }

    /**
     * Retrieve one deterministic newest-first batch after a subject cursor.
     *
     * @return EloquentCollection<int, ActivityLog>
     */
    public function forSubjectBatch(
        Model $subject,
        int $limit = 100,
        ?ActivityLog $cursor = null,
    ): EloquentCollection {
        $subjectId = $this->modelIdentifier($subject);
        if ($subjectId === null) {
            return new EloquentCollection;
        }

        $query = $this->subjectQuery($subject->getMorphClass(), $subjectId);

        if ($cursor instanceof ActivityLog) {
            $cursorId = $cursor->getKey();
            $cursorCreatedAt = $cursor->getRawOriginal('created_at');

            if ($cursorCreatedAt === null) {
                $query
                    ->whereNull('created_at')
                    ->where('id', '<', $cursorId);
            } else {
                $query->where(function (Builder $cursorQuery) use ($cursorCreatedAt, $cursorId): void {
                    $cursorQuery
                        ->where('created_at', '<', $cursorCreatedAt)
                        ->orWhere(function (Builder $sameTimestampQuery) use ($cursorCreatedAt, $cursorId): void {
                            $sameTimestampQuery
                                ->where('created_at', $cursorCreatedAt)
                                ->where('id', '<', $cursorId);
                        })
                        ->orWhereNull('created_at');
                });
            }
        }

        return $query
            ->limitLatest($limit)
            ->get();
    }

    /**
     * Retrieve subject activity rows within a bounded date range.
     *
     * @return EloquentCollection<int, ActivityLog>
     */
    public function forSubjectInDateRange(
        string $subjectType,
        string|int $subjectId,
        CarbonInterface $startDate,
        CarbonInterface $endDate,
        int $limit = 100,
    ): EloquentCollection {
        return $this->subjectQuery($subjectType, $subjectId)
            ->withinDateRange($startDate, $endDate)
            ->limitLatest($limit)
            ->get();
    }

    /**
     * Paginate activity rows for the given subject key.
     *
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    public function paginateForSubjectKey(
        string $subjectType,
        string|int $subjectId,
        int $perPage = 20,
    ): LengthAwarePaginator {
        return $this->subjectQuery($subjectType, $subjectId)
            ->paginate($this->clampPerPage($perPage));
    }

    /**
     * Paginate activity rows for exact subject type and identifier pairs.
     *
     * @param  list<ActivitySubjectReference>  $subjects
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    public function paginateForSubjectReferences(
        array $subjects,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $perPage = $this->clampPerPage($perPage);

        $subjects = $this->normalizeSubjectReferences($subjects);

        $grouped = [];
        $seen = [];

        foreach ($subjects as $subject) {
            $identifier = (string) $subject->id;
            $deduplicationKey = mb_strlen($subject->type).':'.$subject->type.$identifier;

            if (isset($seen[$deduplicationKey])) {
                continue;
            }

            $seen[$deduplicationKey] = true;
            $groupKey = 'type:'.$subject->type;
            $grouped[$groupKey] ??= [
                'type' => $subject->type,
                'ids' => [],
            ];
            $grouped[$groupKey]['ids'][] = $identifier;
        }

        if ($grouped === []) {
            return new LengthAwarePaginator(
                items: [],
                total: 0,
                perPage: $perPage,
                currentPage: max(1, LengthAwarePaginator::resolveCurrentPage()),
                options: ['path' => LengthAwarePaginator::resolveCurrentPath()],
            );
        }

        $query = ActivityLog::query()->where(function (ActivityLogBuilder $subjectsQuery) use ($grouped): void {
            $first = true;

            foreach ($grouped as $subjectGroup) {
                $subjectType = $subjectGroup['type'];
                $subjectIds = $subjectGroup['ids'];
                $constraint = static function (ActivityLogBuilder $subjectQuery) use ($subjectType, $subjectIds): void {
                    $subjectQuery
                        ->where('subject_type', $subjectType)
                        ->whereIn('subject_id', $subjectIds);
                };

                if ($first) {
                    $subjectsQuery->where($constraint);
                    $first = false;
                } else {
                    $subjectsQuery->orWhere($constraint);
                }
            }
        });

        return $this->newestSubjectQuery($query)->paginate($perPage);
    }

    /**
     * Paginate activity rows where the given model key is either subject or causer.
     *
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    public function paginateForSubjectOrCauserKey(
        string $modelType,
        string|int $modelId,
        int $perPage = 20,
    ): LengthAwarePaginator {
        return ActivityLog::forDisplay()
            ->where(function (Builder $query) use ($modelType, $modelId): void {
                $query
                    ->where(function (Builder $subjectQuery) use ($modelType, $modelId): void {
                        $subjectQuery
                            ->where('subject_type', $modelType)
                            ->where('subject_id', $modelId);
                    })
                    ->orWhere(function (Builder $causerQuery) use ($modelType, $modelId): void {
                        $causerQuery
                            ->where('causer_type', $modelType)
                            ->where('causer_id', $modelId);
                    });
            })
            ->paginate($perPage);
    }

    /**
     * Build the canonical deterministic subject timeline query.
     */
    private function subjectQuery(string $subjectType, string|int $subjectId): ActivityLogBuilder
    {
        return $this->newestSubjectQuery(
            ActivityLog::query()->forSubject($subjectType, $subjectId),
        );
    }

    /** Apply the canonical null-safe subject timeline ordering. */
    private function newestSubjectQuery(ActivityLogBuilder $query): ActivityLogBuilder
    {
        return $query
            ->orderByRaw('CASE WHEN created_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /** Clamp a consumer-controlled subject page size to the package boundary. */
    private function clampPerPage(int $perPage): int
    {
        return min(100, max(1, $perPage));
    }

    /**
     * Validate untrusted runtime array values while preserving a precise public contract.
     *
     * @param  array<int, mixed>  $subjects
     * @return list<ActivitySubjectReference>
     */
    private function normalizeSubjectReferences(array $subjects): array
    {
        if (count($subjects) > 100) {
            throw new InvalidArgumentException('Activity subject reads may contain at most 100 references.');
        }

        $references = [];

        foreach ($subjects as $subject) {
            if (! $subject instanceof ActivitySubjectReference) {
                throw new InvalidArgumentException('Activity subject reads require ActivitySubjectReference values.');
            }

            $references[] = $subject;
        }

        return $references;
    }

    /**
     * Resolve a persisted Eloquent model identifier.
     */
    private function modelIdentifier(Model $model): string|int|null
    {
        $identifier = $model->getKey();

        return is_string($identifier) || is_int($identifier) ? $identifier : null;
    }
}
