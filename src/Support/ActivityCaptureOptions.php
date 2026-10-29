<?php

declare(strict_types=1);

namespace Nvl\Activity\Support;

use InvalidArgumentException;

/**
 * Explicit model fields and lifecycle events admitted for optional native capture.
 *
 * @api
 */
final readonly class ActivityCaptureOptions
{
    /** @var list<string> */
    public array $fields;

    /** @var list<string> */
    public array $events;

    /**
     * @param  array<array-key,mixed>  $fields
     * @param  array<array-key,mixed>  $events
     */
    public function __construct(array $fields, array $events = ['created', 'updated', 'deleted', 'restored'], public bool $onlyDirty = true)
    {
        $this->fields = self::names($fields);
        $this->events = self::names($events);
        if (array_diff($this->events, ['created', 'updated', 'deleted', 'restored']) !== []) {
            throw new InvalidArgumentException('Unsupported Activity capture lifecycle event.');
        }
    }

    /** @param array<array-key,mixed> $values
     * @return list<string> */
    private static function names(array $values): array
    {
        if (! array_is_list($values) || count($values) > 100) {
            throw new InvalidArgumentException('Activity capture requires bounded name lists.');
        }
        $names = [];
        foreach ($values as $value) {
            if (! is_string($value) || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,254}$/D', $value) !== 1 || in_array($value, $names, true)) {
                throw new InvalidArgumentException('Activity capture requires unique explicit names.');
            }
            $names[] = $value;
        }

        return $names;
    }
}
