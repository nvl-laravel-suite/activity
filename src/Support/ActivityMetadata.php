<?php

declare(strict_types=1);

namespace Nvl\Activity\Support;

use InvalidArgumentException;

/**
 * Bounded immutable JSON facts with recursive credential redaction.
 *
 * @internal
 */
final class ActivityMetadata
{
    /** @return array<string, mixed>|null */
    public static function nullableRecord(mixed $value, string $field): ?array
    {
        return $value === null ? null : self::record($value, $field);
    }

    /** @return array<string, mixed> */
    public static function record(mixed $value, string $field): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('Activity '.$field.' must be a JSON record.');
        }
        foreach ($value as $key => $item) {
            if (! is_string($key) || ! self::jsonValue($item, 0)) {
                throw new InvalidArgumentException('Activity '.$field.' must contain bounded JSON values.');
            }
        }
        $json = json_encode($value, JSON_THROW_ON_ERROR);
        if (strlen($json) > 65536) {
            throw new InvalidArgumentException('Activity metadata must not exceed 65536 bytes per record.');
        }

        return $value;
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $values
     * @return array<TKey, mixed>
     */
    public static function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && self::namesSecret($key)) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = self::redact($value);
            }
        }

        return $values;
    }

    /**
     * Report whether a key names a credential rather than merely containing one of the words.
     *
     * The key is split into segments on `_`, `-`, `.` and camelCase boundaries. A segment ending in a
     * credential word (`token`, `api_token`, `accessToken`, `csrftoken`) is a secret; `secretary_id` is not.
     * Keys whose last segment describes the credential (`token_count`, `authorization_status`) are kept.
     */
    private static function namesSecret(string $key): bool
    {
        $segments = strtolower((string) preg_replace(
            ['/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '/[^A-Za-z0-9]+/'],
            '_',
            $key,
        ));

        return preg_match('/(?:^|_)[a-z0-9]*(?:passwords?|secrets?|tokens?|api_?keys?|authorization|cookies?)(?:hash|key|value)?(?:_|$)/', $segments) === 1
            && preg_match('/_(?:count|status|type|length|at)$/', $segments) !== 1;
    }

    private static function jsonValue(mixed $value, int $depth): bool
    {
        if ($depth > 16) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::jsonValue($item, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_bool($value) || is_int($value) || is_string($value) || (is_float($value) && is_finite($value));
    }
}
