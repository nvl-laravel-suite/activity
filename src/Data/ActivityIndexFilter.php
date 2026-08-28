<?php

declare(strict_types=1);

namespace Nvl\Activity\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Normalized filter contract for the activity index listing.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class ActivityIndexFilter extends Data
{
    use DataTransform;

    /**
     * Create normalized activity index filters.
     *
     * @param  list<string>  $events
     */
    public function __construct(
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $search,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $event,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $causerId,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?CarbonImmutable $createdAtFrom,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?CarbonImmutable $createdAtTo,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $subjectType = null,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $subjectId = null,
        #[LiteralTypeScriptType('number')]
        public readonly int $perPage = 20,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string[]')]
        public readonly array $events = [],
    ) {}

    /**
     * Build normalized activity index filters from transport-neutral input.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $perPage = filter_var($input['per_page'] ?? 20, FILTER_VALIDATE_INT);
        $event = self::stringFromInput($input, 'event');

        return new self(
            search: self::stringFromInput($input, 'search'),
            event: $event,
            causerId: self::stringFromInput($input, 'causer_id'),
            createdAtFrom: self::dateFromInput($input['created_at_from'] ?? null),
            createdAtTo: self::dateFromInput(
                $input['created_at_to'] ?? null,
                endOfDayForDateOnly: true,
            ),
            subjectType: self::stringFromInput($input, 'subject_type'),
            subjectId: self::stringFromInput($input, 'subject_id'),
            perPage: min(100, max(1, $perPage !== false ? $perPage : 20)),
            events: self::eventsFromInput($input['events'] ?? null, $event),
        );
    }

    /**
     * Normalize explicit and legacy event filters in caller order.
     *
     * @return list<string>
     */
    private static function eventsFromInput(mixed $input, ?string $legacyEvent): array
    {
        if ($input === null) {
            $values = [];
        } elseif (is_string($input)) {
            $values = explode(',', $input);
        } elseif (is_array($input)) {
            if (count($input) > 100) {
                throw new InvalidArgumentException('Activity event filters may contain at most 100 input values.');
            }

            $values = array_values($input);
        } else {
            throw new InvalidArgumentException('Activity events must be an array or comma-separated string.');
        }

        if ($legacyEvent !== null) {
            $values[] = $legacyEvent;
        }

        $events = [];
        $seen = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException('Activity event filters must contain only strings.');
            }

            $value = trim($value);

            if ($value === '' || isset($seen[$value])) {
                continue;
            }

            if (mb_strlen($value) > 100) {
                throw new InvalidArgumentException('Activity event filters may not exceed 100 characters each.');
            }

            $seen[$value] = true;
            $events[] = $value;

            if (count($events) > 10) {
                throw new InvalidArgumentException('Activity event filters may contain at most 10 unique values.');
            }
        }

        return $events;
    }

    /**
     * Read a trimmed nullable string from normalized input.
     *
     * @param  array<string, mixed>  $input
     */
    private static function stringFromInput(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * Parse a nullable date filter from request input.
     */
    private static function dateFromInput(mixed $value, bool $endOfDayForDateOnly = false): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        $input = trim($value);

        if ($input === '') {
            return null;
        }

        $date = CarbonImmutable::make($input);

        if (! $date instanceof CarbonImmutable) {
            return null;
        }

        if ($endOfDayForDateOnly && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input) === 1) {
            return $date->endOfDay();
        }

        return $date;
    }
}
