<?php

declare(strict_types=1);

namespace Nvl\Activity\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Nvl\Activity\Models\ActivityLog;

/**
 * Authorizes and validates historical activity-causer suggestion queries.
 */
final class ListActivityCauserSuggestionsRequest extends ActivityFormRequest
{
    /**
     * Determine whether the actor can view activity causer suggestions.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', ActivityLog::class) ?? false;
    }

    /**
     * Return validated causer-suggestion query rules.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:50'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * Resolve the normalized optional search term.
     */
    public function search(): ?string
    {
        $search = $this->validated('search');

        if (! is_string($search)) {
            return null;
        }

        $search = trim($search);

        return $search !== '' ? $search : null;
    }

    /**
     * Determine whether a supplied search term is too short to query safely.
     */
    public function hasShortSearch(): bool
    {
        $search = $this->search();

        return $search !== null && mb_strlen($search) < 2;
    }

    /**
     * Resolve the bounded suggestion count.
     */
    public function limit(): int
    {
        return $this->integer('limit', 10);
    }

    /**
     * Normalize the generated short search alias before validation.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('search') && $this->has('q')) {
            $this->merge(['search' => $this->input('q')]);
        }
    }
}
