<?php

declare(strict_types=1);

namespace Nvl\Activity\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Nvl\Activity\Actions\Activity\ListActivitiesAction;
use Nvl\Activity\Contracts\MergesActivity;
use Nvl\Activity\Contracts\QueueActivityLogPurgeContract;
use Nvl\Activity\Data\ActivityPurgeQueuedResult;
use Nvl\Activity\Data\Display\ActivityItem;
use Nvl\Activity\Enums\ActivityResponseCode;
use Nvl\Activity\Exceptions\ActivityTimelineException;
use Nvl\Activity\Http\Requests\ActivityTimelineRequest;
use Nvl\Activity\Http\Requests\ListActivityLogsRequest;
use Nvl\Activity\Http\Requests\PurgeActivityLogsRequest;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivitySubjectTimelineResolver;
use Nvl\Data\Data\PaginatedCollection;

/**
 * Canonical JSON API controller for Activity log reads and maintenance actions.
 */
final class ActivityLogsApiController extends Controller
{
    /**
     * List normalized activity rows through the canonical API envelope.
     *
     * @param  ListActivityLogsRequest  $request  Validated index request.
     * @param  ListActivitiesAction  $action  Activity listing action.
     * @return JsonResponse Canonical paginated activity response.
     *
     * @queryParam search string Optional search text matched against description, event, log, and subject type.
     * @queryParam event string Optional exact event key filter.
     * @queryParam events string[] Optional array or comma-separated list of up to ten exact event keys.
     * @queryParam causer_id string Optional stored causer identifier value from activity rows.
     * @queryParam causerId string Optional generated DTO alias for causer_id.
     * @queryParam subject_type string Optional stored subject type value from activity rows.
     * @queryParam subjectType string Optional generated DTO alias for subject_type.
     * @queryParam subject_id string Optional stored subject identifier value from activity rows.
     * @queryParam subjectId string Optional generated DTO alias for subject_id.
     * @queryParam created_at_from string Optional inclusive lower bound date.
     * @queryParam createdAtFrom string Optional generated DTO alias for created_at_from.
     * @queryParam created_at_to string Optional inclusive upper bound date.
     * @queryParam createdAtTo string Optional generated DTO alias for created_at_to.
     * @queryParam per_page integer Optional page size from 1 to 100.
     * @queryParam perPage integer Optional generated DTO alias for per_page.
     * @queryParam limit integer Optional API alias for page size from 1 to 100.
     * @queryParam page integer Optional page number.
     *
     * @throws ValidationException
     */
    public function index(ListActivityLogsRequest $request, ListActivitiesAction $action): JsonResponse
    {
        $activities = $action->execute($request->filters());
        $activities->appends($request->query());

        return response()->json([
            'data' => [
                'activities' => PaginatedCollection::fromPaginator($activities, ActivityItem::class)->toArray(),
            ],
        ], 200);
    }

    /**
     * Return the host-owned merged timeline for one activity-aware subject.
     *
     * @param  ActivityTimelineRequest  $request  Validated timeline request.
     * @param  ActivitySubjectTimelineResolver  $resolver  Subject timeline resolver.
     * @return JsonResponse Canonical simple response containing data.activity.
     *
     * @queryParam subject_type string required Stored morph type or model class for the timeline host.
     * @queryParam subjectType string Optional generated DTO alias for subject_type.
     * @queryParam subject_id string required Primary key for the timeline host.
     * @queryParam subjectId string Optional generated DTO alias for subject_id.
     * @queryParam limit integer Optional maximum merged timeline rows from 1 to 100. Defaults to 100.
     *
     * @throws ValidationException
     */
    public function timeline(
        ActivityTimelineRequest $request,
        ActivitySubjectTimelineResolver $resolver,
    ): JsonResponse {
        $subject = $resolver->resolve($request->subjectType(), $request->subjectId());

        if (Gate::denies('viewTimeline', [ActivityLog::class, $subject])) {
            throw ActivityTimelineException::subjectNotFound(
                $subject::class,
                $this->modelIdentifier($subject),
            );
        }

        return response()->json(['data' => [
            'activity' => $this->timelineItems($subject, $request->limit()),
        ]], 200);
    }

    /**
     * Queue a purge for all activity rows older than the requested retention window.
     *
     * @param  PurgeActivityLogsRequest  $request  Validated purge request.
     * @param  QueueActivityLogPurgeContract  $action  Purge queue action.
     * @return JsonResponse Canonical simple queue result response.
     */
    public function purge(
        PurgeActivityLogsRequest $request,
        QueueActivityLogPurgeContract $action,
    ): JsonResponse {
        $days = $request->days();
        $includeImportant = $request->includeImportant();
        $action->execute($days, false, $includeImportant);

        return $this->purgeQueuedResponse($days, false, $includeImportant);
    }

    /**
     * Queue a purge for system-generated activity rows older than the requested retention window.
     *
     * @param  PurgeActivityLogsRequest  $request  Validated purge request.
     * @param  QueueActivityLogPurgeContract  $action  Purge queue action.
     * @return JsonResponse Canonical simple queue result response.
     */
    public function purgeSystem(
        PurgeActivityLogsRequest $request,
        QueueActivityLogPurgeContract $action,
    ): JsonResponse {
        $days = $request->days();
        $includeImportant = $request->includeImportant();
        $action->execute($days, true, $includeImportant);

        return $this->purgeQueuedResponse($days, true, $includeImportant);
    }

    /**
     * Build the canonical translated response for a queued purge operation.
     */
    private function purgeQueuedResponse(
        int $days,
        bool $systemOnly,
        bool $includeImportant,
    ): JsonResponse {
        $responseCode = $systemOnly
            ? ActivityResponseCode::PurgeSystemQueued
            : ActivityResponseCode::PurgeQueued;

        return response()->json([
            'data' => (new ActivityPurgeQueuedResult(
                queued: true,
                days: $days,
                systemOnly: $systemOnly,
                includeImportant: $includeImportant,
            ))->toArray(),
            'code' => $responseCode->value,
            'message' => $responseCode->getMessage(),
        ], 200);
    }

    /**
     * Transform merged timeline DTO rows into API-safe arrays.
     *
     * @param  MergesActivity  $subject  Host model that owns the timeline.
     * @param  int  $limit  Maximum number of newest rows to return.
     * @return array<int, array<string, mixed>>
     */
    private function timelineItems(MergesActivity $subject, int $limit): array
    {
        return array_map(
            static fn (ActivityItem $activity): array => $activity->toArray(),
            $subject->buildActivityTimeline($limit),
        );
    }

    /**
     * Resolve a safe diagnostic identifier for a model timeline host.
     */
    private function modelIdentifier(Model $model): string
    {
        $identifier = $model->getKey();

        return is_string($identifier) || is_int($identifier)
            ? (string) $identifier
            : '';
    }
}
