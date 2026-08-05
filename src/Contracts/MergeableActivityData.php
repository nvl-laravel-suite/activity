<?php

declare(strict_types=1);

namespace Nvl\Activity\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Nvl\Activity\Data\Display\ActivityItem;

/**
 * Defines a DTO that can collect and translate host-owned records into activity items.
 */
interface MergeableActivityData extends TranslatesToActivity
{
    /**
     * Collect timeline items for the given subject model.
     *
     * @return Collection<int, ActivityItem>
     */
    public static function collectToActivityFor(Model $subject, ?int $limit = null): Collection;
}
