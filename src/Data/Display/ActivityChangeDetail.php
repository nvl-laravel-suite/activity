<?php

declare(strict_types=1);

namespace Nvl\Activity\Data\Display;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Typed representation of a single field diff within an activity entry.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class ActivityChangeDetail extends Data
{
    use DataTransform;

    /**
     * Create one normalized activity field change.
     */
    public function __construct(
        #[LiteralTypeScriptType('string')]
        public readonly string $key,
        #[LiteralTypeScriptType('string')]
        public readonly string $label,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $old,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $new,
        #[LiteralTypeScriptType('string')]
        public readonly string $description,
    ) {}
}
