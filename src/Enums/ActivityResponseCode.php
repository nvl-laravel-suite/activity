<?php

declare(strict_types=1);

namespace Nvl\Activity\Enums;

use Nvl\Support\Contracts\ResponseCode;

/**
 * Catalog of stable Activity API response discriminators.
 */
enum ActivityResponseCode: string implements ResponseCode
{
    case PurgeQueued = 'purge_queued';
    case PurgeSystemQueued = 'purge_system_queued';

    case InvalidConfiguration = 'invalid_configuration';
    case InvalidMapping = 'invalid_mapping';
    case InvalidBatchIdentifier = 'invalid_batch_identifier';
    case InvalidActivityMetadata = 'invalid_activity_metadata';
    case InvalidPurgeCriteria = 'invalid_purge_criteria';
    case TimelineSubjectNotFound = 'timeline_subject_not_found';

    /**
     * Return the localized safe response message.
     */
    public function getMessage(): string
    {
        return (string) trans("activity::activity/general.responses.{$this->value}");
    }
}
