<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Nvl\Activity\Data\Display\ActivityChangeDetail;
use Nvl\Activity\Support\TimelineActivityRules;
use Spatie\Activitylog\Models\Activity;

/**
 * Builds human-readable diff details for normalized activity entries.
 */
final class ActivityDiffBuilder
{
    /**
     * Create the diff builder with label and sentence rendering capabilities.
     */
    public function __construct(
        private readonly LabelResolver $labelResolver,
        private readonly HeadlineRenderer $headlineRenderer,
    ) {}

    /**
     * Build structured change details from a raw activity payload.
     *
     * @param  array<string, mixed>  $properties
     * @return Collection<int, ActivityChangeDetail>
     */
    public function build(Activity $activity, array $properties): Collection
    {
        /** @var array<string, mixed> $changes */
        $changes = (array) Arr::get($properties, 'attributes', Arr::get($properties, 'new', []));
        /** @var array<string, mixed> $oldValues */
        $oldValues = (array) Arr::get($properties, 'old', []);
        $changedKeys = array_values(array_unique([
            ...array_keys($changes),
            ...array_keys($oldValues),
        ]));

        return collect($changedKeys)
            ->map(fn (string|int $key): ?ActivityChangeDetail => $this->buildChangeDetail(
                activity: $activity,
                key: $key,
                value: $changes[$key] ?? null,
                oldValues: $oldValues,
            ))
            ->filter(static fn (?ActivityChangeDetail $detail): bool => $detail !== null)
            ->values();
    }

    /**
     * Build one normalized change detail or omit an unchanged/noisy value.
     *
     * @param  array<string, mixed>  $oldValues
     */
    private function buildChangeDetail(
        Activity $activity,
        string|int $key,
        mixed $value,
        array $oldValues,
    ): ?ActivityChangeDetail {
        $oldValue = $oldValues[$key] ?? null;

        if ($oldValue === $value || TimelineActivityRules::isNoisyChangeKey((string) $key)) {
            return null;
        }

        $label = $this->labelResolver->resolveFieldLabel((string) $key, $activity);
        $resolvedLabel = $label !== '' ? $label : (string) $key;
        $oldFormatted = $this->formatDiffValue($key, $oldValue, $activity);
        $newFormatted = $this->formatDiffValue($key, $value, $activity);

        return new ActivityChangeDetail(
            key: (string) $key,
            label: $resolvedLabel,
            old: $oldFormatted,
            new: $newFormatted,
            description: $this->headlineRenderer->buildChangedText(
                $resolvedLabel,
                $oldFormatted,
                $newFormatted,
            ),
        );
    }

    /**
     * Format a diff value for display.
     */
    private function formatDiffValue(string|int $key, mixed $value, Activity $activity): ?string
    {
        if ($value === null) {
            return null;
        }

        $mapped = $this->labelResolver->resolveFieldValue((string) $key, $value, $activity);

        if ($mapped === null) {
            return null;
        }

        if (is_string($mapped)) {
            return $mapped;
        }

        if (is_bool($mapped)) {
            return $mapped ? 'true' : 'false';
        }

        if (is_numeric($mapped)) {
            return (string) $mapped;
        }

        return $this->stringifyValue($mapped);
    }

    /**
     * Safely stringify a value for human-readable output.
     */
    private function stringifyValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : '';
    }
}
