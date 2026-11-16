<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use BackedEnum;
use InvalidArgumentException;
use Nvl\Activity\Support\ActivityCaptureOptions;

/**
 * Reviews public Spatie v5 LogOptions without loading an external logging engine.
 *
 * @api
 */
final class SpatieActivityCaptureAdapter
{
    /**
     * Supply the model's effective Spatie lifecycle events, including any override.
     * Review every unsupported setting before swapping the model's capture trait.
     *
     * @param  array<array-key, mixed>  $events
     * @return array{options:?ActivityCaptureOptions,unsupported:list<string>}
     */
    public function review(object $options, array $events): array
    {
        $data = get_object_vars($options);
        $defaults = [
            'logName' => null, 'logEmptyChanges' => true, 'logFillable' => false,
            'logOnlyDirty' => false, 'logUnguarded' => false,
            'logAttributes' => [], 'logExceptAttributes' => [], 'dontLogIfAttributesChangedOnly' => [],
            'attributeRawValues' => [], 'descriptionForEvent' => null,
        ];
        $unsupported = array_keys(array_diff_key($data, $defaults));
        foreach (['logFillable', 'logUnguarded', 'dontLogIfAttributesChangedOnly', 'attributeRawValues', 'descriptionForEvent'] as $name) {
            if (! array_key_exists($name, $data) || $data[$name] !== $defaults[$name]) {
                $unsupported[] = $name;
            }
        }
        $fields = $this->explicitFields($data['logAttributes'] ?? null);
        $except = $this->explicitFields($data['logExceptAttributes'] ?? null);
        if ($fields === null) {
            $unsupported[] = 'logAttributes';
        }
        if ($except === null) {
            $unsupported[] = 'logExceptAttributes';
        }
        $dirty = $data['logOnlyDirty'] ?? null;
        $empty = $data['logEmptyChanges'] ?? null;
        $name = $data['logName'] ?? null;
        if (! is_bool($dirty)) {
            $unsupported[] = 'logOnlyDirty';
        }
        if (! is_bool($empty)) {
            $unsupported[] = 'logEmptyChanges';
        }
        if (! array_key_exists('logName', $data) || ($name !== null && ! is_string($name))) {
            $unsupported[] = 'logName';
        }
        $supportedEvents = [];
        if (! array_is_list($events)) {
            $unsupported[] = 'events';
        }
        foreach ($events as $event) {
            $value = $event instanceof BackedEnum ? $event->value : $event;
            if (! in_array($value, ['created', 'updated', 'deleted', 'restored'], true)) {
                $unsupported[] = is_string($value) ? 'events.'.$value : 'events';
            } else {
                $supportedEvents[] = $value;
            }
        }
        if ($unsupported !== [] || $fields === null || $except === null || ! is_bool($dirty) || ! is_bool($empty) || ($name !== null && ! is_string($name))) {
            return ['options' => null, 'unsupported' => array_values(array_unique($unsupported))];
        }
        try {
            $capture = new ActivityCaptureOptions(array_values(array_diff($fields, $except)), array_values(array_unique($supportedEvents)), $dirty, ! $empty, $name);
        } catch (InvalidArgumentException) {
            return ['options' => null, 'unsupported' => ['logName']];
        }

        return ['options' => $capture, 'unsupported' => []];
    }

    /** @return list<string>|null */
    private function explicitFields(mixed $fields): ?array
    {
        if (! is_array($fields) || ! array_is_list($fields) || count($fields) > 100) {
            return null;
        }
        $result = [];
        foreach ($fields as $field) {
            if (! is_string($field) || preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,254}$/D', $field) !== 1) {
                return null;
            }
            $result[] = $field;
        }

        return array_values(array_unique($result));
    }
}
