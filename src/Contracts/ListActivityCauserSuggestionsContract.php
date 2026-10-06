<?php

declare(strict_types=1);

namespace Nvl\Activity\Contracts;

use Nvl\Activity\Data\Display\ActivityCauserSuggestion;
use Spatie\LaravelData\DataCollection;

/**
 * Defines the application boundary for historical activity causer suggestions.
 *
 * @api
 */
interface ListActivityCauserSuggestionsContract
{
    /**
     * Retrieve recently active historical causers, including soft-deleted models when supported.
     *
     * @return DataCollection<int, ActivityCauserSuggestion>
     */
    public function execute(?string $search = null, int $limit = 10): DataCollection;
}
