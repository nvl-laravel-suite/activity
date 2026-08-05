<?php

declare(strict_types=1);

namespace Nvl\Activity\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates one explicitly authorized host timeline request.
 */
final class ActivityTimelineRequest extends ActivityFormRequest
{
    /**
     * Defer authorization until the configured subject has been resolved.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Return validated timeline query rules.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'subject_type' => ['required', 'string', 'max:255'],
            'subject_id' => ['required', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Resolve the requested stored morph type or model class.
     */
    public function subjectType(): string
    {
        return $this->string('subject_type')->toString();
    }

    /**
     * Resolve the requested subject primary key.
     */
    public function subjectId(): string
    {
        return $this->string('subject_id')->toString();
    }

    /**
     * Resolve the bounded timeline row limit.
     */
    public function limit(): int
    {
        return $this->integer('limit', 100);
    }

    /**
     * Normalize generated camel-case aliases before validation.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach ([
            'subject_type' => 'subjectType',
            'subject_id' => 'subjectId',
        ] as $target => $alias) {
            if (! $this->has($target) && $this->has($alias)) {
                $normalized[$target] = $this->input($alias);
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }
}
