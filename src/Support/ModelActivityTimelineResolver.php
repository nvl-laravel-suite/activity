<?php

declare(strict_types=1);

namespace Nvl\Activity\Support;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Nvl\Activity\Data\Display\ActivityItem;
use Nvl\Activity\Services\ModelActivityTimelineService;

/**
 * Bridges activity-aware models to the provider-registered timeline service.
 *
 * Eloquent traits cannot receive constructor dependencies, so the provider
 * assigns the canonical service during boot and traits delegate here without
 * reaching into the container themselves.
 */
final class ModelActivityTimelineResolver
{
    private static ?ModelActivityTimelineService $service = null;

    /**
     * Register the model timeline service for trait consumers.
     */
    public static function use(ModelActivityTimelineService $service): void
    {
        self::$service = $service;
    }

    /**
     * Resolve the transformed base activity timeline for a model subject.
     *
     * @param  int|null  $limit  Optional maximum number of newest activity rows to return
     * @return array<int, ActivityItem>
     */
    public static function forSubject(Model $subject, ?int $limit = null): array
    {
        if (! self::$service instanceof ModelActivityTimelineService) {
            throw new LogicException('Activity model timeline service has not been registered.');
        }

        return self::$service->forSubject($subject, $limit);
    }
}
