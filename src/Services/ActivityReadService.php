<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Nvl\Activity\Builders\ActivityLogBuilder;
use Nvl\Activity\Data\ActivityIndexFilter;
use Nvl\Activity\Models\ActivityLog;

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
            ->paginate($perPage);
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
        return ActivityLog::query()
            ->forSubject($subjectType, $subjectId)
            ->orderByRaw('CASE WHEN created_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
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
