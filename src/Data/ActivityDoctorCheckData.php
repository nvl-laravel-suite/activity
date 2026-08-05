<?php

declare(strict_types=1);

namespace Nvl\Activity\Data;

use Nvl\Activity\Enums\ActivityDoctorSeverity;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One non-mutating activity package health check.
 */
#[TypeScript]
final class ActivityDoctorCheckData extends Data
{
    /**
     * Create one immutable package health-check result.
     */
    public function __construct(
        public readonly string $key,
        public readonly ActivityDoctorSeverity $severity,
        public readonly bool $passed,
        public readonly string $message,
    ) {}
}
