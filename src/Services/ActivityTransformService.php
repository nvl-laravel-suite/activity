<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Nvl\Activity\Data\Display\ActivityItem;
use Nvl\Activity\Models\ActivityLog;

/**
 * Orchestrates collection normalization and signal-timeline filtering for Activity entries.
 */
final class ActivityTransformService
{
    /**
     * Create the transformation pipeline with safe relation hydration and filtering.
     */
    public function __construct(
        private readonly ActivityEntryNormalizer $activityEntryNormalizer,
        private readonly ActivityRelationLoader $activityRelationLoader,
        private readonly TimelineFilter $timelineFilter,
    ) {}

    /**
     * Normalize a collection of Activity log models to canonical frontend DTOs without timeline filtering.
     *
     * @param  Collection<int, ActivityLog>  $activities
     * @return array<int, ActivityItem>
     */
    public function normalizeActivities(Collection $activities): array
    {
        /** @var EloquentCollection<int, ActivityLog> $activityModels */
        $activityModels = $activities instanceof EloquentCollection
            ? $activities
            : new EloquentCollection($activities->all());

        $this->activityRelationLoader->load($activityModels);

        return $this->activityEntryNormalizer->normalizeCollection($activityModels);
    }

    /**
     * Transform a collection of Activity log models to signal-timeline DTOs.
     *
     * @param  Collection<int, ActivityLog>  $activities
     * @return array<int, ActivityItem>
     */
    public function transformActivities(Collection $activities): array
    {
        /** @var array<int, ActivityItem> $transformed */
        $transformed = collect($this->normalizeActivities($activities))
            ->filter(fn (ActivityItem $activity): bool => $this->timelineFilter->shouldIncludeInSignalTimeline($activity))
            ->values()
            ->all();

        return $transformed;
    }

    /**
     * Normalize a single Activity model to a frontend-friendly structure.
     *
     * @param  ActivityLog  $activity  Activity instance
     */
    public function normalizeActivity(ActivityLog $activity): ActivityItem
    {
        /** @var EloquentCollection<int, ActivityLog> $activities */
        $activities = new EloquentCollection([$activity]);
        $this->activityRelationLoader->load($activities);

        return $this->activityEntryNormalizer->normalize($activity);
    }

    /**
     * Transform and filter activities by specific event types.
     *
     * @param  Collection<int, ActivityLog>  $activities  Activities to filter
     * @param  array<string>  $allowedEvents  Event names to include
     * @return array<int, ActivityItem>
     */
    public function transformActivitiesByEvents(Collection $activities, array $allowedEvents): array
    {
        $filtered = $activities->filter(
            fn (ActivityLog $activity): bool => in_array($activity->event, $allowedEvents, true)
        );

        return $this->transformActivities($filtered);
    }
}
