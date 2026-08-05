<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Activity\Data\ActivityIndexFilter;
use Nvl\Activity\Data\Display\ActivityItem;
use Nvl\Activity\Data\Display\ActivityItemProperties;
use Nvl\Activity\Enums\EntrySource;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityReadService;
use Nvl\Activity\Services\ActivityRelationLoader;
use Nvl\Activity\Services\ActivityTransformService;
use Nvl\Activity\Services\ModelActivityTimelineService;
use Nvl\Activity\Services\TimelineFilter;
use Nvl\Activity\Tests\Stubs\TestActivityTimelineSubject;
use Nvl\Activity\Tests\Stubs\TestUuidActivitySubject;

beforeEach(function (): void {
    Schema::create('activity_timeline_subjects', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    Schema::create('activity_uuid_subjects', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('name');
    });
});

afterEach(function (): void {
    Schema::dropIfExists('activity_uuid_subjects');
    Schema::dropIfExists('activity_timeline_subjects');
});

test('subject timelines stay complete deterministic and parity safe after final filtering', function (): void {
    $subject = TestActivityTimelineSubject::query()->create(['name' => 'Timeline subject']);
    $subjectType = $subject->getMorphClass();
    $subjectId = (string) $subject->getKey();
    $sharedTimestamp = CarbonImmutable::parse('2026-08-02 12:00:00');
    $firstVisibleId = '00000000-0000-4000-8000-000000000001';
    $secondVisibleId = '00000000-0000-4000-8000-000000000002';
    $rows = [];

    for ($index = 0; $index < 101; $index++) {
        $createdAt = $sharedTimestamp->addSeconds($index + 1);
        $rows[] = [
            'id' => (string) Str::uuid(),
            'log_name' => 'timeline-read',
            'description' => 'Hidden '.$index,
            'event' => 'created',
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'causer_type' => null,
            'causer_id' => null,
            'attribute_changes' => null,
            'properties' => json_encode(['visibility' => 'hidden'], JSON_THROW_ON_ERROR),
            'batch_uuid' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    for ($index = 0; $index < 101; $index++) {
        $createdAt = $index < 2
            ? $sharedTimestamp
            : $sharedTimestamp->subSeconds($index - 1);
        $rows[] = [
            'id' => match ($index) {
                0 => $firstVisibleId,
                1 => $secondVisibleId,
                default => (string) Str::uuid(),
            },
            'log_name' => 'timeline-read',
            'description' => 'Visible '.$index,
            'event' => 'created',
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'causer_type' => null,
            'causer_id' => null,
            'attribute_changes' => null,
            'properties' => json_encode(['visibility' => 'timeline'], JSON_THROW_ON_ERROR),
            'batch_uuid' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    collect($rows)
        ->chunk(50)
        ->each(static fn ($chunk): bool => ActivityLog::query()->insert($chunk->all()));

    $readService = app(ActivityReadService::class);
    $timelineService = app(ModelActivityTimelineService::class);
    $unloadedTimeline = $timelineService->forSubject($subject);

    expect($readService->forSubject($subject, null))->toHaveCount(202)
        ->and($unloadedTimeline)->toHaveCount(101)
        ->and(array_map(static fn (ActivityItem $item): string => $item->id, array_slice($unloadedTimeline, 0, 2)))
        ->toBe([$secondVisibleId, $firstVisibleId]);

    $subject->setRelation(
        'activitiesAsSubject',
        new EloquentCollection([ActivityLog::query()->findOrFail($firstVisibleId)]),
    );

    $preloadedTimeline = $timelineService->forSubject($subject);
    $limitedTimeline = $timelineService->forSubject($subject, 2);

    expect(array_map(static fn (ActivityItem $item): string => $item->id, $preloadedTimeline))
        ->toBe(array_map(static fn (ActivityItem $item): string => $item->id, $unloadedTimeline))
        ->and(array_map(static fn (ActivityItem $item): string => $item->id, $limitedTimeline))
        ->toBe([$secondVisibleId, $firstVisibleId]);
});

test('timestamp peers remain distinct without description based guessing', function (): void {
    $subject = TestActivityTimelineSubject::query()->create(['name' => 'Timestamp subject']);
    $createdAt = CarbonImmutable::parse('2026-08-02 14:00:00');
    $activities = [
        [
            'description' => 'updated',
            'event' => 'updated',
            'properties' => [
                'visibility' => 'timeline',
                'attributes' => ['name' => 'After'],
                'old' => ['name' => 'Before'],
            ],
        ],
        [
            'description' => 'Approved',
            'event' => 'approved',
            'properties' => ['visibility' => 'timeline'],
        ],
        [
            'description' => 'created',
            'event' => 'created',
            'properties' => ['visibility' => 'timeline'],
        ],
        [
            'description' => 'Customer registered',
            'event' => 'created',
            'properties' => ['visibility' => 'timeline'],
        ],
    ];

    foreach ($activities as $activity) {
        ActivityLog::query()->create([
            ...$activity,
            'log_name' => 'timestamp-peers',
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (string) $subject->getKey(),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    $transformed = app(ActivityTransformService::class)->transformActivities(
        ActivityLog::forSubjectTimeline($subject->getMorphClass(), (string) $subject->getKey())->get(),
    );

    expect($transformed)->toHaveCount(4)
        ->and(array_map(static fn (ActivityItem $item): string => $item->description, $transformed))
        ->toContain('updated', 'Approved', 'created', 'Customer registered');
});

test('old only removals produce structured change details', function (): void {
    $activity = ActivityLog::query()->create([
        'log_name' => 'diffs',
        'description' => 'Removed legacy value',
        'event' => 'updated',
        'properties' => [
            'attributes' => [],
            'old' => ['legacy_status' => 'enabled'],
        ],
    ]);

    $item = app(ActivityTransformService::class)->normalizeActivity($activity);

    expect($item->changesDetailed)->toHaveCount(1)
        ->and($item->changesDetailed[0]->key)->toBe('legacy_status')
        ->and($item->changesDetailed[0]->old)->toBe('enabled')
        ->and($item->changesDetailed[0]->new)->toBeNull();
});

test('malformed integer and uuid morph identifiers are excluded before eager loading', function (): void {
    $integerSubject = TestActivityTimelineSubject::query()->create(['name' => 'Integer subject']);
    $uuidSubject = TestUuidActivitySubject::query()->create(['name' => 'UUID subject']);
    $rows = [
        ['description' => 'valid integer', 'subject_type' => $integerSubject->getMorphClass(), 'subject_id' => '+'.(string) $integerSubject->getKey()],
        ['description' => 'invalid integer', 'subject_type' => $integerSubject->getMorphClass(), 'subject_id' => 'not-an-integer'],
        ['description' => 'valid uuid', 'subject_type' => $uuidSubject->getMorphClass(), 'subject_id' => strtoupper((string) $uuidSubject->getKey())],
        ['description' => 'invalid uuid', 'subject_type' => $uuidSubject->getMorphClass(), 'subject_id' => 'not-a-uuid'],
    ];

    foreach ($rows as $row) {
        ActivityLog::query()->create([
            ...$row,
            'log_name' => 'morph-identifiers',
            'event' => 'created',
        ]);
    }

    $activities = ActivityLog::query()
        ->where('log_name', 'morph-identifiers')
        ->get()
        ->keyBy('description');
    app(ActivityRelationLoader::class)->load(new EloquentCollection($activities->values()->all()));

    $validInteger = $activities->get('valid integer')?->getRelation('subject');
    $validUuid = $activities->get('valid uuid')?->getRelation('subject');

    expect($validInteger)->toBeInstanceOf(TestActivityTimelineSubject::class)
        ->and($validInteger?->is($integerSubject))->toBeTrue()
        ->and($activities->get('invalid integer')?->getRelation('subject'))->toBeNull()
        ->and($validUuid)->toBeInstanceOf(TestUuidActivitySubject::class)
        ->and($validUuid?->is($uuidSubject))->toBeTrue()
        ->and($activities->get('invalid uuid')?->getRelation('subject'))->toBeNull();
});

test('read service paginators remain transport neutral', function (): void {
    ActivityLog::query()->create([
        'log_name' => 'transport',
        'description' => 'First',
        'event' => 'created',
    ]);
    ActivityLog::query()->create([
        'log_name' => 'transport',
        'description' => 'Second',
        'event' => 'created',
    ]);
    request()->query->set('event', 'created');

    $paginator = app(ActivityReadService::class)->paginateIndex(
        ActivityIndexFilter::fromInput(['per_page' => 1]),
    );

    expect($paginator->nextPageUrl())->not->toContain('event=created');
});

test('historical visibility values fail closed unless explicitly canonical', function (?string $visibility, bool $included): void {
    $payload = $visibility === null ? [] : ['visibility' => $visibility];
    $item = new ActivityItem(
        id: 'visibility-row',
        log: 'visibility',
        event: 'created',
        source: EntrySource::ActivityLog,
        properties: ActivityItemProperties::fromPayload($payload),
    );

    expect((new TimelineFilter)->shouldIncludeInSignalTimeline($item))->toBe($included);
})->with([
    'absent legacy value' => [null, true],
    'blank legacy value' => [' ', true],
    'canonical timeline value' => ['timeline', true],
    'uppercase noncanonical value' => ['TIMELINE', false],
    'unknown value' => ['consumer_only', false],
    'audit value' => ['audit_only', false],
    'hidden value' => ['hidden', false],
]);
