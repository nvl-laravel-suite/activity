<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Illuminate\Database\Eloquent\Model;
use Nvl\Activity\Data\Display\ActivityItem;
use Nvl\Activity\Models\ActivityLog;

/**
 * Composes transformed activity timelines for concrete subject models.
 */
final class ModelActivityTimelineService
{
    private const int READ_BATCH_SIZE = 100;

    /**
     * Create the subject timeline service with read and transformation capabilities.
     */
    public function __construct(
        private readonly ActivityReadService $activityReadService,
        private readonly ActivityTransformService $activityTransformService,
    ) {}

    /**
     * Build the user-facing merged activity timeline for a subject.
     *
     * @return array<int, ActivityItem>
     */
    public function forSubject(Model $subject, ?int $limit = null): array
    {
        if ($limit !== null && $limit <= 0) {
            return [];
        }

        $timeline = [];
        $cursor = null;

        do {
            $activities = $this->activityReadService->forSubjectBatch(
                subject: $subject,
                limit: self::READ_BATCH_SIZE,
                cursor: $cursor,
            );

            foreach ($this->activityTransformService->transformActivities($activities) as $activity) {
                $timeline[] = $activity;

                if ($limit !== null && count($timeline) >= $limit) {
                    return $timeline;
                }
            }

            $lastActivity = $activities->last();
            $cursor = $lastActivity instanceof ActivityLog ? $lastActivity : null;
        } while ($cursor instanceof ActivityLog && $activities->count() === self::READ_BATCH_SIZE);

        return $timeline;
    }
}
