<?php

declare(strict_types=1);

use Nvl\Activity\Data\Display\ActivityItem;
use Nvl\Activity\Enums\EntrySource;
use Nvl\Activity\Services\TimelineFilter;
use Nvl\Activity\Support\ActivityTimelineData;
use Nvl\Activity\Tests\Stubs\TestActivityTimelineHost;
use Nvl\Activity\Traits\MergesActivityTimeline;

it('merges and sorts activity sources by newest timestamp first', function (): void {
    $baseActivity = new ActivityItem(
        id: 'activity-1',
        log: 'default',
        event: 'updated',
        source: EntrySource::ActivityLog,
        createdAt: '2026-04-02T10:00:00+00:00',
        headline: 'Base activity',
    );

    $mailActivity = new ActivityItem(
        id: 'mail-1',
        log: 'mail_notifications',
        event: 'mail_sent',
        source: EntrySource::Mail,
        createdAt: '2026-04-02T11:00:00+00:00',
        headline: 'Mail activity',
    );

    $merged = ActivityTimelineData::merge([$baseActivity], [$mailActivity]);

    expect($merged)->toHaveCount(2)
        ->and($merged[0]->id)->toBe('mail-1')
        ->and($merged[1]->id)->toBe('activity-1');
});

test('merged timelines compare timezone offsets as absolute instants', function (): void {
    $earlierWithLaterWallClock = new ActivityItem(
        id: 'earlier',
        log: 'default',
        event: 'updated',
        createdAt: '2026-04-02T12:00:00+02:00',
    );
    $laterWithEarlierWallClock = new ActivityItem(
        id: 'later',
        log: 'default',
        event: 'updated',
        createdAt: '2026-04-02T10:30:00+00:00',
    );

    $merged = ActivityTimelineData::merge(
        [$earlierWithLaterWallClock],
        [$laterWithEarlierWallClock],
    );

    expect(array_map(static fn (ActivityItem $item): string => $item->id, $merged))
        ->toBe(['later', 'earlier']);
});

test('merged timeline ordering is deterministic when timestamps match', function (): void {
    $first = new ActivityItem(
        id: 'activity-a',
        log: 'default',
        event: 'updated',
        createdAt: '2026-04-02T10:30:00.123456Z',
    );
    $second = new ActivityItem(
        id: 'activity-b',
        log: 'default',
        event: 'updated',
        createdAt: '2026-04-02T10:30:00.123456Z',
    );

    $merged = ActivityTimelineData::merge([$first, $second]);

    expect(array_map(static fn (ActivityItem $item): string => $item->id, $merged))
        ->toBe(['activity-b', 'activity-a']);
});

test('the legacy timestamp deduplication API preserves distinct events', function (): void {
    $first = new ActivityItem(
        id: 'activity-a',
        log: 'default',
        event: 'created',
        createdAt: '2026-04-02T10:30:00.123456Z',
    );
    $second = new ActivityItem(
        id: 'activity-b',
        log: 'default',
        event: 'updated',
        createdAt: '2026-04-02T10:30:00.123456Z',
    );
    $activities = collect([$first, $second]);

    $result = (new TimelineFilter)->deduplicateByTimestamp($activities);

    expect($result)->toBe($activities)
        ->and($result)->toHaveCount(2);
});

test('merged activity timeline remains unlimited by default', function (): void {
    $host = new TestActivityTimelineHost;

    $timeline = $host->buildActivityTimeline();

    expect($timeline)->toHaveCount(3)
        ->and(array_map(static fn (ActivityItem $item): string => $item->id, $timeline))
        ->toBe(['activity-2', 'mail-1', 'activity-1']);
});

test('merged activity timeline applies an optional final newest first limit', function (): void {
    $host = new TestActivityTimelineHost;

    $timeline = $host->buildActivityTimeline(2);

    expect($timeline)->toHaveCount(2)
        ->and(array_map(static fn (ActivityItem $item): string => $item->id, $timeline))
        ->toBe(['activity-2', 'mail-1'])
        ->and($host->limits)->toBe([2, 2]);
});

test('merged timeline limits backfill base rows removed by supersession', function (): void {
    $host = new class
    {
        use MergesActivityTimeline;

        /** @var list<int|null> */
        public array $baseLimits = [];

        /** @return list<ActivityItem> */
        public function mergedActivities(?int $limit = null): array
        {
            $this->baseLimits[] = $limit;
            $activities = [
                new ActivityItem(
                    id: 'base-4',
                    log: 'default',
                    event: 'note_recorded',
                    source: EntrySource::ActivityLog,
                    createdAt: '2026-04-02T12:00:00+00:00',
                ),
                new ActivityItem(
                    id: 'base-3',
                    log: 'default',
                    event: 'note_recorded',
                    source: EntrySource::ActivityLog,
                    createdAt: '2026-04-02T11:00:00+00:00',
                ),
                new ActivityItem(
                    id: 'base-2',
                    log: 'default',
                    event: 'updated',
                    source: EntrySource::ActivityLog,
                    createdAt: '2026-04-02T10:00:00+00:00',
                ),
                new ActivityItem(
                    id: 'base-1',
                    log: 'default',
                    event: 'created',
                    source: EntrySource::ActivityLog,
                    createdAt: '2026-04-02T09:00:00+00:00',
                ),
            ];

            return $limit === null ? $activities : array_slice($activities, 0, $limit);
        }

        /** @return list<list<ActivityItem>> */
        protected function mergedActivitySources(?int $limit = null): array
        {
            return [[new ActivityItem(
                id: 'note-1',
                log: 'notes',
                event: 'note_added',
                source: EntrySource::Comment,
                createdAt: '2026-04-02T12:30:00+00:00',
            )]];
        }

        /** @return array<string, array<int, string>> */
        protected function mergedActivitySupersededBaseEvents(): array
        {
            return [EntrySource::Comment->value => ['note_recorded']];
        }
    };

    $timeline = $host->buildActivityTimeline(2);

    expect(array_map(static fn (ActivityItem $item): string => $item->id, $timeline))
        ->toBe(['note-1', 'base-2'])
        ->and($host->baseLimits)->toBe([2, 4]);
});
