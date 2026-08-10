<?php

declare(strict_types=1);

namespace Nvl\Activity\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Nvl\Activity\Models\ActivityLog;

/**
 * Validate purge requests for activity log cleanup endpoints.
 */
final class PurgeActivityLogsRequest extends ActivityFormRequest
{
    /**
     * Determine whether the actor can queue activity purge operations.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('delete', ActivityLog::class) ?? false;
    }

    /**
     * Define Scribe documentation metadata for the purge payload.
     *
     * @return array<string, array{description: string, example: int|bool}>
     */
    public function bodyParameters(): array
    {
        return [
            'days' => [
                'description' => (string) trans(
                    'activity::activity/general.api.parameters.purge_days',
                ),
                'example' => $this->allowedPurgeOptions()[0] ?? 90,
            ],
            'include_important' => [
                'description' => (string) trans(
                    'activity::activity/general.api.parameters.include_important',
                ),
                'example' => false,
            ],
        ];
    }

    /**
     * Define validation rules for activity purge requests.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'days' => ['required', 'integer', 'in:'.implode(',', $this->allowedPurgeOptions())],
            'include_important' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Resolve the validated retention window from the request payload.
     */
    public function days(): int
    {
        return $this->integer('days');
    }

    /**
     * Determine whether protected important evidence was explicitly included.
     */
    public function includeImportant(): bool
    {
        return $this->boolean('include_important');
    }

    /**
     * Resolve allowed purge retention windows from module configuration.
     *
     * @return array<int, int>
     */
    private function allowedPurgeOptions(): array
    {
        /** @var array<int, int> $options */
        $options = config('activity.retention.allowed_purge_options', [90, 365, 730]);

        return $options;
    }
}
