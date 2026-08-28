<?php

declare(strict_types=1);

namespace Nvl\Activity\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;
use Nvl\Activity\Data\ActivityIndexFilter;
use Nvl\Activity\Models\ActivityLog;

/**
 * Authorizes and validates activity index query filters.
 */
final class ListActivityLogsRequest extends ActivityFormRequest
{
    /**
     * Determine whether the actor can view the global activity index.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', ActivityLog::class) ?? false;
    }

    /**
     * Return validated activity index rules.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', 'string', 'max:100'],
            'events' => [
                'nullable',
                function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        ActivityIndexFilter::fromInput([
                            'event' => $this->input('event'),
                            'events' => $value,
                        ]);
                    } catch (InvalidArgumentException) {
                        $fail((string) trans('activity::activity/general.validation.invalid_events'));
                    }
                },
            ],
            'causer_id' => ['nullable', 'string', 'max:100'],
            'subject_type' => ['nullable', 'string', 'max:255'],
            'subject_id' => ['nullable', 'string', 'max:100'],
            'created_at_from' => ['nullable', 'date'],
            'created_at_to' => ['nullable', 'date', 'after_or_equal:created_at_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Build the transport-neutral index filter DTO.
     */
    public function filters(): ActivityIndexFilter
    {
        return ActivityIndexFilter::fromInput([
            'search' => $this->input('search'),
            'event' => $this->input('event'),
            'events' => $this->input('events'),
            'causer_id' => $this->input('causer_id'),
            'created_at_from' => $this->input('created_at_from'),
            'created_at_to' => $this->input('created_at_to'),
            'subject_type' => $this->input('subject_type'),
            'subject_id' => $this->input('subject_id'),
            'per_page' => $this->input('per_page'),
        ]);
    }

    /**
     * Normalize generated camel-case aliases before validation.
     */
    protected function prepareForValidation(): void
    {
        $this->mergeAliases([
            'causer_id' => ['causerId'],
            'subject_type' => ['subjectType'],
            'subject_id' => ['subjectId'],
            'created_at_from' => ['createdAtFrom'],
            'created_at_to' => ['createdAtTo'],
            'per_page' => ['perPage', 'limit'],
        ]);
    }

    /**
     * Merge the first supplied alias for each canonical request key.
     *
     * @param  array<string, list<string>>  $aliases
     */
    private function mergeAliases(array $aliases): void
    {
        $normalized = [];

        foreach ($aliases as $target => $candidates) {
            if ($this->has($target)) {
                continue;
            }

            foreach ($candidates as $candidate) {
                if (! $this->has($candidate)) {
                    continue;
                }

                $normalized[$target] = $this->input($candidate);

                break;
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }
}
