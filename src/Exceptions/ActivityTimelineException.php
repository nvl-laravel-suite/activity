<?php

declare(strict_types=1);

namespace Nvl\Activity\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Response;
use Nvl\Activity\Enums\ActivityResponseCode;

/**
 * Reports safe failures while resolving an allowlisted activity timeline host.
 */
final class ActivityTimelineException extends ActivityException implements ShouldntReport
{
    /**
     * Create a not-found failure without exposing model implementation details.
     */
    public static function subjectNotFound(string $modelClass, string $subjectId): self
    {
        return new self(
            message: (string) trans('activity::activity/general.errors.timeline.subject_not_found'),
            responseCode: ActivityResponseCode::TimelineSubjectNotFound,
            suggestedStatus: Response::HTTP_NOT_FOUND,
            diagnosticContext: [
                'model_class' => $modelClass,
                'subject_id' => $subjectId,
            ],
        );
    }
}
