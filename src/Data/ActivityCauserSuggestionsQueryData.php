<?php

declare(strict_types=1);

namespace Nvl\Activity\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Validated historical causer suggestion query. */
#[TypeScript]
final class ActivityCauserSuggestionsQueryData extends ActivityInputData
{
    public function __construct(
        public readonly ?string $search = null,
        public readonly ?int $limit = null,
    ) {}

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:50'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function searchTerm(): ?string
    {
        $search = trim($this->search ?? '');

        return $search !== '' ? $search : null;
    }

    public function hasShortSearch(): bool
    {
        $search = $this->searchTerm();

        return $search !== null && mb_strlen($search) < 2;
    }
}
