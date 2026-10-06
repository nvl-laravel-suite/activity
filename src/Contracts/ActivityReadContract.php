<?php

declare(strict_types=1);

namespace Nvl\Activity\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Nvl\Activity\Data\ActivityIndexFilter;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Support\ActivitySubjectReference;

/**
 * Defines supported activity history reads returning typed identity/result handles.
 *
 * @api
 */
interface ActivityReadContract
{
    /**
     * Retrieve newest activity rows for the global dashboard feed.
     *
     * @return EloquentCollection<int, ActivityLog>
     */
    public function latest(int $limit = 12): EloquentCollection;

    /**
     * Build the paginated Activity index listing.
     *
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    public function paginateIndex(ActivityIndexFilter $filters): LengthAwarePaginator;

    /**
     * Retrieve newest activity rows for a concrete subject model.
     *
     * @return EloquentCollection<int, ActivityLog>
     */
    public function forSubject(Model $subject, ?int $limit = 100): EloquentCollection;

    /**
     * Retrieve newest activity rows for the given subject key.
     *
     * @return EloquentCollection<int, ActivityLog>
     */
    public function forSubjectKey(
        string $subjectType,
        string|int $subjectId,
        ?int $limit = 100,
    ): EloquentCollection;

    /**
     * Retrieve one deterministic newest-first batch after a subject cursor.
     *
     * @return EloquentCollection<int, ActivityLog>
     */
    public function forSubjectBatch(
        Model $subject,
        int $limit = 100,
        ?ActivityLog $cursor = null,
    ): EloquentCollection;

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
    ): EloquentCollection;

    /**
     * Paginate activity rows for the given subject key.
     *
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    public function paginateForSubjectKey(
        string $subjectType,
        string|int $subjectId,
        int $perPage = 20,
    ): LengthAwarePaginator;

    /**
     * Paginate activity rows for exact subject type and identifier pairs.
     *
     * @param  list<ActivitySubjectReference>  $subjects
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    public function paginateForSubjectReferences(
        array $subjects,
        int $perPage = 20,
    ): LengthAwarePaginator;

    /**
     * Paginate activity rows where the given model key is either subject or causer.
     *
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    public function paginateForSubjectOrCauserKey(
        string $modelType,
        string|int $modelId,
        int $perPage = 20,
    ): LengthAwarePaginator;
}
