<?php

declare(strict_types=1);

namespace Nvl\Activity\Support;

/**
 * Shared rules for signal-only activity timelines.
 */
final class TimelineActivityRules
{
    /**
     * Universal technical attributes ignored when consumers do not override them.
     *
     * @var list<string>
     */
    private const array DEFAULT_IGNORED_ATTRIBUTES = [
        'created_at',
        'updated_at',
        'deleted_at',
        'remember_token',
    ];

    /**
     * Check whether a consumer-configured technical attribute should be suppressed.
     *
     * @param  string  $key  Change key name
     * @return bool Whether the key is noisy
     */
    public static function isNoisyChangeKey(string $key): bool
    {
        $normalized = strtolower(trim($key));
        $configured = config('activity.capture.ignored_attributes', self::DEFAULT_IGNORED_ATTRIBUTES);
        $ignoredAttributes = is_array($configured)
            ? array_values(array_filter(
                $configured,
                static fn (mixed $attribute): bool => is_string($attribute) && $attribute !== '',
            ))
            : self::DEFAULT_IGNORED_ATTRIBUTES;

        return in_array($normalized, $ignoredAttributes, true);
    }
}
