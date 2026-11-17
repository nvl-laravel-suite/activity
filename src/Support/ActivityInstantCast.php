<?php

declare(strict_types=1);

namespace Nvl\Activity\Support;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Stores activity instants as UTC microseconds independently of the host timezone.
 *
 * A local wall clock repeats inside a daylight-saving overlap, so storing one would lose the
 * instant and misorder facts. The columns are `timestamp(6)` without a time zone, so the stored
 * text carries no offset; a string written without an offset is read in the host timezone,
 * matching Laravel's own date parsing.
 *
 * @implements CastsAttributes<Carbon, DateTimeInterface|string>
 *
 * @internal
 */
final class ActivityInstantCast implements CastsAttributes
{
    /** Stored text format: UTC wall clock with the six fractional digits the columns keep. */
    public const string FORMAT = 'Y-m-d H:i:s.u';

    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException('Activity timestamps require UTC strings.');
        }

        return Carbon::parse($value, 'UTC');
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [$key => $value === null ? null : self::format($value)];
    }

    /** Format one instant exactly as it is stored, for writes and comparisons. */
    public static function format(DateTimeInterface|string $instant): string
    {
        $date = $instant instanceof DateTimeInterface ? Carbon::instance($instant) : Carbon::parse($instant);

        return $date->utc()->format(self::FORMAT);
    }
}
