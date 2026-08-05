<?php

declare(strict_types=1);

namespace Nvl\Activity\Actions\Activity;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Nvl\Activity\Data\ActivityIndexFilter;
use Nvl\Activity\Data\Display\ActivityItem;
use Nvl\Activity\Services\ActivityReadService;
use Nvl\Activity\Services\ActivityTransformService;

/**
 * Lists system activities with pagination and filtering.
 */
final class ListActivitiesAction
{
    /**
     * Create the activity listing action with its read and transformation capabilities.
     */
    public function __construct(
        private readonly ActivityReadService $activityReadService,
        private readonly ActivityTransformService $activityTransformService,
    ) {}

    /**
     * Execute the activity listing with optional filters.
     *
     * @return LengthAwarePaginator<int, ActivityItem> Paginated normalized activities
     */
    public function execute(ActivityIndexFilter $filters): LengthAwarePaginator
    {
        $activities = $this->activityReadService->paginateIndex($filters);

        /** @var Collection<int, ActivityItem> $normalizedActivities */
        $normalizedActivities = collect(
            $this->activityTransformService->normalizeActivities($activities->getCollection())
        );

        return new LengthAwarePaginator(
            items: $normalizedActivities,
            total: $activities->total(),
            perPage: $activities->perPage(),
            currentPage: $activities->currentPage(),
            options: $activities->getOptions(),
        );
    }
}
