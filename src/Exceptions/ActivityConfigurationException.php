<?php

declare(strict_types=1);

namespace Nvl\Activity\Exceptions;

use Illuminate\Http\Response;
use Nvl\Activity\Enums\ActivityResponseCode;

/**
 * Reports invalid package, mapping, or activity-model configuration.
 */
final class ActivityConfigurationException extends ActivityException
{
    /**
     * Create a failure for an empty configured activity table.
     */
    public static function emptyTableName(): self
    {
        return new self(
            message: (string) trans('activity::activity/general.errors.configuration.empty_table'),
            responseCode: ActivityResponseCode::InvalidConfiguration,
            suggestedStatus: Response::HTTP_INTERNAL_SERVER_ERROR,
            diagnosticContext: ['configuration' => 'activity.storage.table'],
        );
    }

    /**
     * Create a failure for an invalid configured storage connection.
     */
    public static function invalidConnectionName(): self
    {
        return new self(
            message: (string) trans('activity::activity/general.errors.configuration.invalid_connection'),
            responseCode: ActivityResponseCode::InvalidConfiguration,
            suggestedStatus: Response::HTTP_INTERNAL_SERVER_ERROR,
            diagnosticContext: ['configuration' => 'activity.storage.connection'],
        );
    }

    /**
     * Create a failure for a non-Eloquent configured activity model.
     */
    public static function nonEloquentActivityModel(): self
    {
        return new self(
            message: (string) trans('activity::activity/general.errors.configuration.non_eloquent_activity_model'),
            responseCode: ActivityResponseCode::InvalidConfiguration,
            suggestedStatus: Response::HTTP_INTERNAL_SERVER_ERROR,
            diagnosticContext: ['configuration' => 'activitylog.activity_model'],
        );
    }

    /**
     * Create a failure for a mapping that targets a non-Eloquent class.
     */
    public static function invalidMappingModel(string $mappingClass, string $modelClass): self
    {
        return new self(
            message: (string) trans('activity::activity/general.errors.configuration.invalid_mapping_model'),
            responseCode: ActivityResponseCode::InvalidMapping,
            suggestedStatus: Response::HTTP_INTERNAL_SERVER_ERROR,
            diagnosticContext: [
                'mapping_class' => $mappingClass,
                'model_class' => $modelClass,
            ],
        );
    }

    /**
     * Create a failure for a mapping with an empty log name.
     */
    public static function emptyMappingLogName(string $mappingClass): self
    {
        return new self(
            message: (string) trans('activity::activity/general.errors.configuration.empty_mapping_log_name'),
            responseCode: ActivityResponseCode::InvalidMapping,
            suggestedStatus: Response::HTTP_INTERNAL_SERVER_ERROR,
            diagnosticContext: ['mapping_class' => $mappingClass],
        );
    }

    /**
     * Create a failure when provider order attempts to replace an existing model mapping.
     */
    public static function duplicateMapping(string $mappingClass, string $modelClass): self
    {
        return new self(
            message: (string) trans('activity::activity/general.errors.configuration.duplicate_mapping'),
            responseCode: ActivityResponseCode::InvalidMapping,
            suggestedStatus: Response::HTTP_INTERNAL_SERVER_ERROR,
            diagnosticContext: [
                'mapping_class' => $mappingClass,
                'model_class' => $modelClass,
            ],
        );
    }
}
