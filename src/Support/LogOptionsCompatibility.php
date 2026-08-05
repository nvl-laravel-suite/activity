<?php

declare(strict_types=1);

namespace Nvl\Activity\Support;

use Closure;
use RuntimeException;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Normalizes LogOptions behavior across Spatie Activitylog v4 and v5.
 */
final class LogOptionsCompatibility
{
    /**
     * Disable activity records when none of the configured attributes changed.
     */
    public static function dontLogEmptyChanges(LogOptions $logOptions): LogOptions
    {
        foreach (['dontLogEmptyChanges', 'dontSubmitEmptyLogs'] as $method) {
            if (! is_callable([$logOptions, $method])) {
                continue;
            }

            Closure::fromCallable([$logOptions, $method])();

            return $logOptions;
        }

        throw new RuntimeException('The installed Spatie Activitylog LogOptions implementation cannot disable empty logs.');
    }
}
