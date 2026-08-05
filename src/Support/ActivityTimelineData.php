<?php

declare(strict_types=1);

namespace Nvl\Activity\Support;

use DateTimeImmutable;
use DateTimeInterface;
use Nvl\Activity\Data\Display\ActivityItem;

/**
 * Merges already-normalized activity sources into one canonical timeline sequence.
 *
 * This helper intentionally stays dumb: flatten, sort, and return. Source-specific
 * inclusion or suppression rules belong to the host model or the source translator.
 */
final class ActivityTimelineData
{
    /**
     * @param  iterable<int|string, ActivityItem>  ...$sources
     * @return array<int, ActivityItem>
     */
    public static function merge(iterable ...$sources): array
    {
        $activityItems = [];

        foreach ($sources as $source) {
            foreach ($source as $item) {
                $activityItems[] = $item;
            }
        }

        usort(
            $activityItems,
            static function (ActivityItem $left, ActivityItem $right): int {
                $timestampComparison = self::timestamp($right) <=> self::timestamp($left);

                return $timestampComparison !== 0
                    ? $timestampComparison
                    : strcmp($right->id, $left->id);
            },
        );

        return $activityItems;
    }

    /**
     * Resolve an ISO-8601 timestamp to comparable epoch microseconds.
     */
    private static function timestamp(ActivityItem $item): int
    {
        if ($item->createdAt === null || trim($item->createdAt) === '') {
            return PHP_INT_MIN;
        }

        foreach (['Y-m-d\TH:i:s.uP', DateTimeInterface::ATOM] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $item->createdAt);

            if ($date instanceof DateTimeImmutable) {
                return ($date->getTimestamp() * 1_000_000) + (int) $date->format('u');
            }
        }

        return PHP_INT_MIN;
    }

    private function __construct() {}
}
