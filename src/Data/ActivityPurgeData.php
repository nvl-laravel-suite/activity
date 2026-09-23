<?php

declare(strict_types=1);

namespace Nvl\Activity\Data;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Validated activity purge options. */
#[MapInputName(SnakeCaseMapper::class)]
#[TypeScript]
final class ActivityPurgeData extends ActivityInputData
{
    public function __construct(
        public readonly int $days,
        public readonly ?bool $includeImportant = null,
    ) {}

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        /** @var list<int> $options */
        $options = config('activity.retention.allowed_purge_options', [90, 365, 730]);

        return [
            'days' => ['required', 'integer', 'in:'.implode(',', $options)],
            'include_important' => ['sometimes', 'boolean'],
        ];
    }
}
