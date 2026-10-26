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
            if (is_string($key) && preg_match('/(?:password|secret|token|api[_-]?key|authorization|cookie)/i', $key) === 1) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = self::redact($value);
            }
        }

        return $values;
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
