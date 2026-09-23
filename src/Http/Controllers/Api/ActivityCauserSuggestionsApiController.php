<?php

declare(strict_types=1);

namespace Nvl\Activity\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Nvl\Activity\Actions\Activity\ListActivityCauserSuggestionsAction;
use Nvl\Activity\Data\ActivityCauserSuggestionsQueryData;
use Nvl\Activity\Http\ActivityRequestInput;
use Nvl\Activity\Models\ActivityLog;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
use Spatie\LaravelData\Support\Wrapping\WrapExecutionType;

/**
 * Canonical JSON API endpoint for Activity causer suggestions.
 */
final class ActivityCauserSuggestionsApiController extends Controller
{
    /**
     * Return historical user causers represented in Activity rows.
     *
     * @param  Request  $request  Suggestion request.
     * @param  ListActivityCauserSuggestionsAction  $action  Activity causer suggestions action.
     * @return JsonResponse Canonical simple suggestion response.
     *
     * @queryParam search string Optional search text matched against configured causer attributes and identifiers.
     * @queryParam q string Optional alias for search.
     * @queryParam limit integer Optional maximum result count from 1 to 50.
     */
    public function __invoke(
        Request $request,
        ListActivityCauserSuggestionsAction $action,
    ): JsonResponse {
        Gate::authorize('viewAny', ActivityLog::class);
        $query = ActivityCauserSuggestionsQueryData::validateAndCreate(ActivityRequestInput::aliased($request, ['search' => ['q']]));

        if ($query->hasShortSearch()) {
            return response()->json(['data' => []], 200);
        }

        $suggestions = $action->execute(
            search: $query->searchTerm(),
            limit: $query->limit ?? 10,
        );

        $payload = $suggestions->transform(
            TransformationContextFactory::create()->withWrapExecutionType(WrapExecutionType::Disabled),
        );

        return response()->json(['data' => $payload], 200);
    }
}
