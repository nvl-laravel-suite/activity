<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Stubs;

use Nvl\Activity\Data\Display\ActivityItem;
use Nvl\Activity\Enums\EntrySource;
use Nvl\Activity\Traits\MergesActivityTimeline;

/**
 * In-memory host fixture for merged timeline ordering and limit tests.
 */
final class TestActivityTimelineHost
{
    use MergesActivityTimeline;

    /** @var list<int|null> */
    public array $limits = [];

    /**
     * @return list<ActivityItem>
     */
    public function mergedActivities(?int $limit = null): array
    {
        $this->limits[] = $limit;

        return [
            new ActivityItem(
                id: 'activity-1',
                log: 'default',
                event: 'created',
                source: EntrySource::ActivityLog,
                createdAt: '2026-04-02T10:00:00+00:00',
                headline: 'Base activity',
            ),
            new ActivityItem(
                id: 'activity-2',
                log: 'default',
                event: 'updated',
                source: EntrySource::ActivityLog,
                createdAt: '2026-04-02T12:00:00+00:00',
                headline: 'Base activity',
            ),
        ];
    }

    /**
     * @return list<list<ActivityItem>>
     */
    protected function mergedActivitySources(?int $limit = null): array
    {
        $this->limits[] = $limit;

        return [[
            new ActivityItem(
                id: 'mail-1',
                log: 'mail_notifications',
                event: 'mail_sent',
                source: EntrySource::Mail,
                createdAt: '2026-04-02T11:00:00+00:00',
                headline: 'Mail activity',
            ),
        ]];
    }
}
