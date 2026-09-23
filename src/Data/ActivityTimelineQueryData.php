<?php

declare(strict_types=1);

namespace Nvl\Activity\Data;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Validated host timeline query. */
#[MapInputName(SnakeCaseMapper::class)]
#[TypeScript]
final class ActivityTimelineQueryData extends ActivityInputData
{
    public function __construct(
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly ?int $limit = null,
    ) {}

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'subject_type' => ['required', 'string', 'max:255'],
            'subject_id' => ['required', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
