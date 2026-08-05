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
 * Typed representation of the activity causer identity for frontend payloads.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class ActivityCauser extends Data
{
    use DataTransform;

    /**
     * Create a normalized activity-causer identity.
     */
    public function __construct(
        #[LiteralTypeScriptType('number | string | null')]
        public readonly string|int|null $id,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $name,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $email,
    ) {}
}
