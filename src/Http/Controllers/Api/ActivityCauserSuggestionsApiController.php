<?php

declare(strict_types=1);

namespace Nvl\Activity\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Nvl\Activity\Actions\Activity\ListActivityCauserSuggestionsAction;
use Nvl\Activity\Http\Requests\ListActivityCauserSuggestionsRequest;
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
     * @param  ListActivityCauserSuggestionsRequest  $request  Validated suggestion request.
     * @param  ListActivityCauserSuggestionsAction  $action  Activity causer suggestions action.
     * @return JsonResponse Canonical simple suggestion response.
     *
     * @queryParam search string Optional search text matched against configured causer attributes and identifiers.
     * @queryParam q string Optional alias for search.
     * @queryParam limit integer Optional maximum result count from 1 to 50.
     */
    public function __invoke(
        ListActivityCauserSuggestionsRequest $request,
        ListActivityCauserSuggestionsAction $action,
    ): JsonResponse {
        if ($request->hasShortSearch()) {
            return response()->json(['data' => []], 200);
        }

        $suggestions = $action->execute(
            search: $request->search(),
            limit: $request->limit(),
        );

        $payload = $suggestions->transform(
            TransformationContextFactory::create()->withWrapExecutionType(WrapExecutionType::Disabled),
        );

        return response()->json(['data' => $payload], 200);
    }
}
