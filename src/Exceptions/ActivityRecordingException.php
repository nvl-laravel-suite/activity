<?php

declare(strict_types=1);

namespace Nvl\Activity\Exceptions;

use Illuminate\Http\Response;
use Nvl\Activity\Enums\ActivityResponseCode;

/**
 * Reports invalid input supplied to the canonical activity recorder.
 */
final class ActivityRecordingException extends ActivityException
{
    /** Reject reuse of an envelope ID for a different immutable event. */
    public static function conflictingEnvelope(): self
    {
        return new self(
            message: (string) trans('activity::activity/general.errors.recording.conflicting_envelope'),
            responseCode: ActivityResponseCode::ConflictingActivityEnvelope,
            suggestedStatus: Response::HTTP_CONFLICT,
        );
    }

    /**
     * Create a failure for an invalid activity batch identifier.
     */
    public static function invalidBatchIdentifier(): self
    {
        return new self(
            message: (string) trans('activity::activity/general.errors.recording.invalid_batch_identifier'),
            responseCode: ActivityResponseCode::InvalidBatchIdentifier,
            suggestedStatus: Response::HTTP_UNPROCESSABLE_ENTITY,
            publicContext: ['field' => 'batch_uuid'],
        );
    }

    /**
     * Create a failure for an unsupported activity metadata classification.
     */
    public static function invalidMetadata(string $field): self
    {
        return new self(
            message: (string) trans(
                'activity::activity/general.errors.recording.invalid_metadata',
                ['field' => $field],
            ),
            responseCode: ActivityResponseCode::InvalidActivityMetadata,
            suggestedStatus: Response::HTTP_UNPROCESSABLE_ENTITY,
            publicContext: ['field' => $field],
        );
    }
}
