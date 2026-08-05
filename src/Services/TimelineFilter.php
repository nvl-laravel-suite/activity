<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Illuminate\Support\Collection;
use Nvl\Activity\Data\Display\ActivityItem;
use Nvl\Activity\Enums\ActivityVisibility;
use Nvl\Activity\Support\TimelineActivityRules;

/**
 * Controls what appears in user-facing timelines.
 *
 * Multi-layer filter: visibility -> event -> signal check.
 */
final class TimelineFilter
{
    /**
     * Determine if an activity entry should appear in signal timelines.
     *
     * @param  ActivityItem  $activity  Normalized activity DTO
     * @return bool Whether the entry should be included
     */
    public function shouldIncludeInSignalTimeline(ActivityItem $activity): bool
    {
        $props = $activity->properties;

        $visibility = is_string($props->visibility) ? trim($props->visibility) : '';
        if ($visibility !== '' && $visibility !== ActivityVisibility::Timeline->value) {
            return false;
        }

        $event = strtolower(trim($activity->event));
        if ($event === '') {
            return false;
        }

        if ($event === 'updated') {
            return $this->passesUpdateSignalCheck($event, $activity);
        }

        return true;
    }

    /**
     * Preserve the historical public API without collapsing distinct events that share a timestamp.
     *
     * @param  Collection<int, ActivityItem>  $activities
     * @return Collection<int, ActivityItem>
     */
    public function deduplicateByTimestamp(Collection $activities): Collection
    {
        return $activities;
    }

    /**
     * Suppress low-signal update logs that only touch noisy technical fields.
     *
     * @param  string  $event  Event name
     * @param  ActivityItem  $activity  Normalized activity DTO
     * @return bool Whether the update passes signal check
     */
    private function passesUpdateSignalCheck(string $event, ActivityItem $activity): bool
    {
        if ($event !== 'updated') {
            return true;
        }

        $changesRaw = $activity->changesDetailed;
        if ($changesRaw === []) {
            return false;
        }

        foreach ($changesRaw as $change) {
            $key = $change->key;
            if ($key !== '' && ! TimelineActivityRules::isNoisyChangeKey($key)) {
                return true;
            }
        }

        return false;
    }
}
