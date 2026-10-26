<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Illuminate\Support\Collection;
use Nvl\Activity\Enums\ActivityVisibility;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Support\TimelineActivityRules;

/**
 * Controls what appears in user-facing timelines.
 *
 * Multi-layer filter: visibility -> event -> signal check.
 *
 * @internal
 */
final class TimelineFilter
{
    /**
     * Determine if an activity entry should appear in signal timelines.
     *
     * @param  ActivityLog  $activity  Native activity model
     * @return bool Whether the entry should be included
     */
    public function shouldIncludeInSignalTimeline(ActivityLog $activity): bool
    {
        $props = $activity->properties;

        $raw = $props?->get('visibility');
        if ($raw !== null && ! is_string($raw)) {
            return false;
        }
        $visibility = trim($raw ?? '');
        if ($visibility !== '' && $visibility !== ActivityVisibility::Timeline->value) {
            return false;
        }

        $event = strtolower($activity->semanticEvent());

        if ($event === 'updated') {
            return $this->passesUpdateSignalCheck($event, $activity);
        }

        return true;
    }

    /**
     * Preserve the historical public API without collapsing distinct events that share a timestamp.
     *
     * @param  Collection<int, ActivityLog>  $activities
     * @return Collection<int, ActivityLog>
     */
    public function deduplicateByTimestamp(Collection $activities): Collection
    {
        return $activities;
    }

    /**
     * Suppress low-signal update logs that only touch noisy technical fields.
     *
     * @param  string  $event  Event name
     * @param  ActivityLog  $activity  Native activity model
     * @return bool Whether the update passes signal check
     */
    private function passesUpdateSignalCheck(string $event, ActivityLog $activity): bool
    {
        if ($event !== 'updated') {
            return true;
        }

        $payload = $activity->attribute_changes?->all() ?? [];
        $changes = $payload['attributes'] ?? [];
        $old = $payload['old'] ?? [];
        if (! is_array($changes) || ! is_array($old)) {
            return false;
        }
        foreach (array_unique([...array_keys($changes), ...array_keys($old)]) as $key) {
            if (! TimelineActivityRules::isNoisyChangeKey((string) $key) && ($changes[$key] ?? null) !== ($old[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
