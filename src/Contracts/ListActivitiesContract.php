<?php

declare(strict_types=1);

namespace Nvl\Activity\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use Nvl\Activity\Data\ActivityIndexFilter;
use Nvl\Activity\Data\Display\ActivityItem;

/**
 * Defines the application boundary for filtered, normalized activity listings.
 *
 * @api
 */
interface ListActivitiesContract
{
    /**
     * Execute the activity listing with optional filters.
     *
     * @return LengthAwarePaginator<int, ActivityItem> Paginated normalized activities
     */
    public function execute(ActivityIndexFilter $filters): LengthAwarePaginator;
}
