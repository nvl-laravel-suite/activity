<?php

declare(strict_types=1);

namespace Nvl\Activity\Data\Display;

use Nvl\Activity\Enums\HeadlineSegmentType;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Typed semantic segment for rendering an activity headline without string parsing.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class HeadlineSegment extends Data
{
    use DataTransform;

    /**
     * Create one semantic headline segment.
     */
    public function __construct(
        #[LiteralTypeScriptType("'text' | 'actor' | 'field' | 'value' | 'status'")]
        public readonly HeadlineSegmentType $type,
        #[LiteralTypeScriptType('string')]
        public readonly string $text,
        #[LiteralTypeScriptType('number | string | null')]
        public readonly string|int|null $causerId = null,
    ) {}
}
