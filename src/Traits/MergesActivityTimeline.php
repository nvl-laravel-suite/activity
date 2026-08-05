<?php

declare(strict_types=1);

namespace Nvl\Activity\Traits;

use Nvl\Activity\Data\Display\ActivityItem;
use Nvl\Activity\Enums\EntrySource;
use Nvl\Activity\Support\ActivityTimelineData;

/**
 * Composes a host-owned merged timeline from base activity plus richer source rows.
 *
 * Host models provide extra already-normalized sources through
 * `mergedActivitySources()`. The trait keeps merge ordering and supersession
 * rules centralized so controllers never shape timelines directly.
 *
 * @method array<int, ActivityItem> mergedActivities(?int $limit = null)
 */
trait MergesActivityTimeline
{
    /**
     * Return additional already-translated activity sources for the host model.
     *
     * @return array<int, iterable<int|string, ActivityItem>>
     */
    abstract protected function mergedActivitySources(?int $limit = null): array;

    /**
     * Declare base activity-log events that should be suppressed when a richer
     * merged source is present in the final timeline.
     *
     * Array shape:
     * [
     *     EntrySource::Comment->value => ['comment_recorded'],
     * ]
     *
     * @return array<string, array<int, string>>
     */
    protected function mergedActivitySupersededBaseEvents(): array
    {
        return [];
    }

    /**
     * Build the full host timeline from base activity plus extra translated sources.
     *
     * @return array<int, ActivityItem>
     */
    public function buildActivityTimeline(?int $limit = null): array
    {
        if ($limit !== null && $limit <= 0) {
            return [];
        }

        $additionalSources = array_map(
            static fn (iterable $source): array => collect($source)->all(),
            $this->mergedActivitySources($limit),
        );

        if ($limit === null) {
            return $this->applyMergedActivitySupersessionRules(ActivityTimelineData::merge(
                $this->mergedActivities(),
                ...$additionalSources,
            ));
        }

        $baseLimit = $limit;

        do {
            $baseActivities = $this->mergedActivities($baseLimit);
            $timeline = $this->applyMergedActivitySupersessionRules(ActivityTimelineData::merge(
                $baseActivities,
                ...$additionalSources,
            ));

            if (count($timeline) >= $limit || count($baseActivities) < $baseLimit) {
                return array_slice($timeline, 0, $limit);
            }

            $nextBaseLimit = $baseLimit > intdiv(PHP_INT_MAX, 2)
                ? PHP_INT_MAX
                : $baseLimit * 2;

            if ($nextBaseLimit === $baseLimit) {
                return array_slice($timeline, 0, $limit);
            }

            $baseLimit = $nextBaseLimit;
        } while (true);
    }

    /**
     * Remove generic base rows that are explicitly superseded by richer merged rows.
     *
     * @param  array<int, ActivityItem>  $timeline
     * @return array<int, ActivityItem>
     */
    private function applyMergedActivitySupersessionRules(array $timeline): array
    {
        $rules = $this->mergedActivitySupersededBaseEvents();

        if ($rules === []) {
            return $timeline;
        }

        $items = collect($timeline);
        $suppressedEvents = collect($rules)
            ->flatMap(function (array $events, string $source) use ($items): array {
                $hasSource = $items->contains(
                    static fn (ActivityItem $item): bool => $item->source->value === $source
                );

                if (! $hasSource) {
                    return [];
                }

                return $events;
            })
            ->filter(static fn (string $event): bool => $event !== '')
            ->unique()
            ->values()
            ->all();

        if ($suppressedEvents === []) {
            return $timeline;
        }

        /** @var array<int, ActivityItem> $refined */
        $refined = $items
            ->reject(
                static fn (ActivityItem $item): bool => $item->source === EntrySource::ActivityLog
                    && in_array($item->event, $suppressedEvents, true)
            )
            ->values()
            ->all();

        return $refined;
    }
}
