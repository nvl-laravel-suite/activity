<?php

declare(strict_types=1);

namespace Nvl\Activity\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Typed result returned after an activity purge operation is queued.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class ActivityPurgeQueuedResult extends Data
{
    use DataTransform;

    /**
     * Create one immutable queued-purge result.
     */
    public function __construct(
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $queued,
        #[LiteralTypeScriptType('number')]
        public readonly int $days,
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $systemOnly,
    ) {}
}
