<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Activity\Data\ActivityIndexFilter;
use Nvl\Activity\Data\Display\ActivityChangeDetail;
use Nvl\Activity\Data\Display\ActivityItemProperties;
use Nvl\Activity\Enums\ActivityImportance;
use Nvl\Activity\Enums\ActivitySource;
use Nvl\Activity\Enums\ActivityVisibility;
use Nvl\Activity\Enums\HeadlineSegmentType;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityDiffBuilder;
use Nvl\Activity\Services\ActivityReadService;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Services\HeadlineRenderer;
use Nvl\Activity\Services\LabelResolver;
use Nvl\Activity\Services\MappingRegistry;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Activity\Support\ModelKeyIdentifierValidator;
use Nvl\Activity\Tests\Stubs\TestActivityMapping;
use Nvl\Activity\Tests\Stubs\TestActivitySubjectWithHasModelActivity;
use Nvl\Activity\Tests\Stubs\TestActivityTimelineSubject;
use Nvl\Activity\Tests\Stubs\TestUuidActivitySubject;
use RuntimeException as ActivityTestRuntimeException;
use Spatie\LaravelData\Optional;

test('typed activity properties reject malformed stable fields while preserving consumer extras', function (): void {
    $properties = ActivityItemProperties::fromPayload([
        'resource_type' => ['unexpected'],
        'resource_id' => true,
        'status' => '  pending_review  ',
        'comment' => 'not-a-record',
        'attributes' => ['name' => 'After'],
        'old' => null,
        'context' => ['reason' => 'manual review'],
        'source' => null,
        'consumer_flag' => true,
        'ignored_extra' => null,
        'ignored_optional' => Optional::create(),
    ]);

    $identifier = ActivityItemProperties::fromPayload(['resource_id' => '  external-42  ']);
    $blankIdentifier = ActivityItemProperties::fromPayload(['resource_id' => '  ']);

    expect($properties->resourceType)->toBeNull()
        ->and($properties->resourceId)->toBeNull()
        ->and($properties->status)->toBe('pending_review')
        ->and($properties->comment)->toBeNull()
        ->and($properties->attributesArray())->toBe(['name' => 'After'])
        ->and($properties->oldArray())->toBe([])
        ->and($properties->contextArray())->toBe(['reason' => 'manual review'])
        ->and($properties->extra)->toBe(['consumer_flag' => true])
        ->and($identifier->resourceId)->toBe('external-42')
        ->and($blankIdentifier->resourceId)->toBeNull();
});

test('activity queries compose every index and subject filter without leaking transport concerns', function (): void {
    $target = ActivityLog::query()->create([
        'log_name' => 'orders',
        'description' => 'Important audit target',
        'event' => 'updated',
        'subject_type' => 'App\\Models\\Order',
        'subject_id' => '42',
        'causer_type' => 'App\\Models\\User',
        'causer_id' => '7',
        'created_at' => '2026-07-15 12:00:00',
        'updated_at' => '2026-07-15 12:00:00',
    ]);
    $secondSubject = ActivityLog::query()->create([
        'log_name' => 'orders',
        'description' => 'Second subject',
        'event' => 'created',
        'subject_type' => 'App\\Models\\Order',
        'subject_id' => '43',
        'causer_type' => 'App\\Models\\User',
        'causer_id' => '8',
        'created_at' => '2026-06-15 12:00:00',
        'updated_at' => '2026-06-15 12:00:00',
    ]);
    ActivityLog::query()->create([
        'log_name' => 'orders',
        'description' => 'Outside filter',
        'event' => 'updated',
        'subject_type' => 'App\\Models\\Order',
        'subject_id' => '42',
        'causer_type' => 'App\\Models\\User',
        'causer_id' => '7',
        'created_at' => '2026-05-15 12:00:00',
        'updated_at' => '2026-05-15 12:00:00',
    ]);

    $filters = ActivityIndexFilter::fromInput([
        'search' => 'audit target',
        'event' => 'updated',
        'causer_id' => '7',
        'subject_type' => 'App\\Models\\Order',
        'subject_id' => '42',
        'created_at_from' => '2026-07-01',
        'created_at_to' => '2026-07-31',
    ]);

    $filtered = ActivityLog::query()->forIndex($filters)->get();
    $legacyDirectFilter = ActivityLog::query()->forIndex(new ActivityIndexFilter(
        search: null,
        event: 'created',
        causerId: null,
        createdAtFrom: null,
        createdAtTo: null,
    ))->get();
    $subjects = ActivityLog::query()
        ->forSubject('App\\Models\\Order', ['42', '43'])
        ->withinDateRange(
            CarbonImmutable::parse('2026-06-01'),
            CarbonImmutable::parse('2026-07-31 23:59:59'),
        )
        ->get();

    expect($filtered)->toHaveCount(1)
        ->and($filtered->first()?->is($target))->toBeTrue()
        ->and($legacyDirectFilter->modelKeys())->toBe([$secondSubject->getKey()])
        ->and($subjects)->toHaveCount(2)
        ->and($subjects->modelKeys())->toContain($target->getKey(), $secondSubject->getKey());
});

test('read services support complete feeds, bounded dates, cursor ties, and subject-or-causer pagination', function (): void {
    Schema::create('activity_timeline_subjects', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    try {
        $subject = new TestActivityTimelineSubject;
        $subject->forceFill(['name' => 'Coverage subject'])->save();
        $otherSubject = new TestActivityTimelineSubject;
        $otherSubject->forceFill(['name' => 'Other subject'])->save();
        $subjectKey = $subject->getKey();
        $otherSubjectKey = $otherSubject->getKey();

        if ((! is_string($subjectKey) && ! is_int($subjectKey))
            || (! is_string($otherSubjectKey) && ! is_int($otherSubjectKey))) {
            throw new ActivityTestRuntimeException('Activity timeline test subjects require scalar identifiers.');
        }

        $subjectType = $subject->getMorphClass();
        $subjectId = (string) $subjectKey;

        ActivityLog::query()->create([
            'log_name' => 'read-service',
            'description' => 'Subject event',
            'event' => 'created',
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'created_at' => '2026-07-10 12:00:00',
            'updated_at' => '2026-07-10 12:00:00',
        ]);
        ActivityLog::query()->create([
            'log_name' => 'read-service',
            'description' => 'Causer event',
            'event' => 'reviewed',
            'subject_type' => $otherSubject->getMorphClass(),
            'subject_id' => (string) $otherSubjectKey,
            'causer_type' => $subjectType,
            'causer_id' => $subjectId,
            'created_at' => '2026-07-11 12:00:00',
            'updated_at' => '2026-07-11 12:00:00',
        ]);

        $olderNullId = '00000000-0000-4000-8000-000000000001';
        $cursorId = '00000000-0000-4000-8000-000000000002';
        $nullTimestampRows = array_map(
            static fn (string $id, string $description): array => [
                'id' => $id,
                'log_name' => 'read-service',
                'description' => $description,
                'event' => 'created',
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'causer_type' => null,
                'causer_id' => null,
                'attribute_changes' => null,
                'properties' => null,
                'batch_uuid' => null,
                'created_at' => null,
                'updated_at' => null,
            ],
            [$olderNullId, $cursorId],
            ['Older null timestamp', 'Cursor null timestamp'],
        );
        DB::table(ActivityLog::DEFAULT_TABLE)->insert($nullTimestampRows);

        $service = app(ActivityReadService::class);
        $cursor = ActivityLog::query()->findOrFail($cursorId);
        $cursorBatch = $service->forSubjectBatch($subject, cursor: $cursor);
        $dateRange = $service->forSubjectInDateRange(
            $subjectType,
            $subjectId,
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31 23:59:59'),
        );
        $subjectPaginator = $service->paginateForSubjectKey($subjectType, $subjectId, 2);
        $relatedPaginator = $service->paginateForSubjectOrCauserKey($subjectType, $subjectId, 10);

        expect($service->forSubject(new TestActivityTimelineSubject))->toHaveCount(0)
            ->and($service->forSubjectBatch(new TestActivityTimelineSubject))->toHaveCount(0)
            ->and($service->forSubjectKey($subjectType, $subjectId, null))->toHaveCount(3)
            ->and($cursorBatch->modelKeys())->toBe([$olderNullId])
            ->and($dateRange)->toHaveCount(1)
            ->and($subjectPaginator->total())->toBe(3)
            ->and($relatedPaginator->total())->toBe(4);
    } finally {
        Schema::dropIfExists('activity_timeline_subjects');
    }
});

test('subject history reads clamp page size and preserve type and identifier pairs', function (): void {
    $rows = [
        ['type-a', '1', 'A1', '2026-07-11 12:00:00'],
        ['type-a', '2', 'A2', '2026-07-12 12:00:00'],
        ['type-b', '1', 'B1', '2026-07-13 12:00:00'],
        ['type-b', '2', 'B2', '2026-07-14 12:00:00'],
    ];

    foreach ($rows as [$type, $id, $description, $createdAt]) {
        ActivityLog::query()->create([
            'log_name' => 'multi-subject',
            'description' => $description,
            'event' => 'created',
            'subject_type' => $type,
            'subject_id' => $id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    $service = app(ActivityReadService::class);
    $single = $service->paginateForSubjectKey('type-a', '1', 1000);
    $multiple = $service->paginateForSubjectReferences([
        new ActivitySubjectReference(' type-a ', '1'),
        new ActivitySubjectReference('type-b', 2),
        new ActivitySubjectReference('type-a', 1),
    ], 1000);

    expect($single->perPage())->toBe(100)
        ->and($multiple->perPage())->toBe(100)
        ->and($multiple->total())->toBe(2)
        ->and($multiple->getCollection()->pluck('description')->all())->toBe(['B2', 'A1']);
});

test('numeric-looking subject types remain string-bound in multi-subject reads', function (): void {
    ActivityLog::query()->create([
        'log_name' => 'numeric-subject-type',
        'description' => 'Numeric type',
        'event' => 'created',
        'subject_type' => '1',
        'subject_id' => 'resource-1',
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $page = app(ActivityReadService::class)->paginateForSubjectReferences([
        new ActivitySubjectReference('1', 'resource-1'),
    ]);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($page->total())->toBe(1)
        ->and($queries)->not->toBeEmpty();

    foreach ($queries as $query) {
        expect($query['bindings'][0] ?? null)->toBeString()->toBe('1');
    }
});

test('subject references reject nul bytes before read or record storage access', function (Closure $operation): void {
    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($operation)->toThrow(InvalidArgumentException::class, 'NUL');

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBe([]);
})->with([
    'read type boundary' => fn () => app(ActivityReadService::class)
        ->paginateForSubjectReferences([new ActivitySubjectReference("type\0", 'resource-1')]),
    'record identifier body' => fn () => app(ActivityRecorder::class)
        ->recordForSubjectReference(
            new ActivitySubjectReference('type', "resource\0-1"),
            'updated',
        ),
]);

test('multi-subject history rejects oversized inputs and returns an empty paginator without querying', function (): void {
    $service = app(ActivityReadService::class);
    $subjects = array_map(
        static fn (int $index): ActivitySubjectReference => new ActivitySubjectReference('type-a', $index),
        range(1, 101),
    );

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect(fn () => $service->paginateForSubjectReferences($subjects))
        ->toThrow(InvalidArgumentException::class, 'at most 100')
        ->and(DB::getQueryLog())->toBe([]);

    DB::flushQueryLog();
    $empty = $service->paginateForSubjectReferences([], 0);
    $emptyQueries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($empty->total())->toBe(0)
        ->and($empty->perPage())->toBe(1)
        ->and($emptyQueries)->toBe([]);
});

test('model identifiers are accepted only when they match integer uuid ulid or declared string storage', function (): void {
    Schema::create('activity_coverage_strings', function (Blueprint $table): void {
        $table->string('identifier')->primary();
    });
    Schema::create('activity_coverage_integers', function (Blueprint $table): void {
        $table->integer('identifier')->primary();
    });
    Schema::create('activity_coverage_small_integers', function (Blueprint $table): void {
        $table->smallInteger('identifier')->primary();
    });

    try {
        $integerModel = new class extends Model {};
        $integerModel->setTable('activity_missing_integer_models');
        $integerModel->setKeyType('int');

        $uuidModel = new TestUuidActivitySubject;

        /** ULID-keyed fixture used to exercise trait-declared storage. */
        $ulidModel = new class extends Model
        {
            use HasUlids;
        };

        $stringModel = new class extends Model {};
        $stringModel->setTable('activity_coverage_strings');
        $stringModel->setKeyName('identifier');
        $stringModel->setKeyType('string');

        $schemaIntegerModel = new class extends Model {};
        $schemaIntegerModel->setTable('activity_coverage_integers');
        $schemaIntegerModel->setKeyName('identifier');
        $schemaIntegerModel->setKeyType('custom');

        $smallIntegerModel = new class extends Model {};
        $smallIntegerModel->setTable('activity_coverage_small_integers');
        $smallIntegerModel->setKeyName('identifier');
        $smallIntegerModel->setKeyType('int');

        $missingColumnModel = new class extends Model {};
        $missingColumnModel->setTable('activity_coverage_strings');
        $missingColumnModel->setKeyName('missing_identifier');
        $missingColumnModel->setKeyType('custom');

        $uuid = (string) Str::uuid();
        $ulid = (string) Str::ulid();
        $validator = new ModelKeyIdentifierValidator;

        expect($validator->validIdentifiers($integerModel, [1, '2', '+0002', '', '2.5', true, null]))
            ->toBe([1, 2, 2])
            ->and($validator->validIdentifiers($uuidModel, [strtoupper($uuid), $ulid, 42]))->toBe([$uuid])
            ->and($validator->validIdentifiers($ulidModel, [strtolower($ulid), $uuid, 42]))->toBe([$ulid])
            ->and($validator->validIdentifiers($stringModel, ['external-key', 42, ' ']))
            ->toBe(['external-key', '42'])
            ->and($validator->validIdentifiers($schemaIntegerModel, ['+0015', 'invalid']))->toBe(['15'])
            ->and($validator->validIdentifiers($smallIntegerModel, ['32767', '32768', '-32769']))
            ->toBe(DB::getDriverName() === 'sqlite' ? [32767, 32768, -32769] : [32767])
            ->and($validator->validIdentifiers($missingColumnModel, ['anything']))->toBe([])
            ->and($validator->isValid($integerModel, 10))->toBeTrue()
            ->and($validator->isValid($integerModel, 'invalid'))->toBeFalse();
    } finally {
        Schema::dropIfExists('activity_coverage_small_integers');
        Schema::dropIfExists('activity_coverage_integers');
        Schema::dropIfExists('activity_coverage_strings');
    }
});

test('canonical recording derives safe diffs and honors explicit enum metadata', function (): void {
    $subject = ActivityLog::query()->create([
        'log_name' => 'recording-subject',
        'description' => 'Before',
        'event' => 'created',
    ]);
    $subject->timestamps = false;
    $subject->forceFill([
        'description' => 'After',
        'updated_at' => CarbonImmutable::parse('2026-08-02 12:00:00'),
    ])->save();

    $recorder = app(ActivityRecorder::class);
    $recorded = $recorder->record(
        subject: $subject,
        event: 'updated',
        description: ' ',
        actor: 'operator-1',
        source: ActivitySource::User,
        visibility: ActivityVisibility::AuditOnly,
        importance: ActivityImportance::Important,
    );

    $subject->syncChanges();
    $unchanged = $recorder->record(
        subject: $subject,
        event: 'updated',
        description: 'No model changes',
    );
    $nonDiff = $recorder->record(
        subject: $subject,
        event: 'viewed',
        description: 'Viewed',
    );
    $explicit = $recorder->record(
        subject: $subject,
        event: 'status_transition',
        description: 'Status changed',
        attributes: ['status' => 'published'],
        old: ['status' => 'draft'],
        resolveChanges: false,
    );

    expect($recorded)->toBeInstanceOf(ActivityLog::class)
        ->and($unchanged)->toBeInstanceOf(ActivityLog::class)
        ->and($nonDiff)->toBeInstanceOf(ActivityLog::class)
        ->and($explicit)->toBeInstanceOf(ActivityLog::class);

    if (! $recorded instanceof ActivityLog
        || ! $unchanged instanceof ActivityLog
        || ! $nonDiff instanceof ActivityLog
        || ! $explicit instanceof ActivityLog) {
        throw new ActivityTestRuntimeException('The canonical recorder must persist the configured Activity model.');
    }

    $subjectKey = $subject->getKey();
    if (! is_string($subjectKey) && ! is_int($subjectKey)) {
        throw new ActivityTestRuntimeException('The recording subject requires a scalar identifier.');
    }

    expect($recorded->description)->toBe('updated')
        ->and($recorded->subject_id)->toBe((string) $subjectKey)
        ->and($recorded->properties?->get('source'))->toBe(ActivitySource::User->value)
        ->and($recorded->properties?->get('visibility'))->toBe(ActivityVisibility::AuditOnly->value)
        ->and($recorded->properties?->get('importance'))->toBe(ActivityImportance::Important->value)
        ->and($recorded->properties?->get('attributes'))->toBe(['description' => 'After'])
        ->and($recorded->properties?->get('old'))->toBe(['description' => 'Before'])
        ->and($unchanged->properties?->has('attributes'))->toBeFalse()
        ->and($nonDiff->properties?->has('attributes'))->toBeFalse()
        ->and($explicit->properties?->get('attributes'))->toBe(['status' => 'published'])
        ->and($explicit->properties?->get('old'))->toBe(['status' => 'draft']);
});

test('diff rendering preserves scalar and structured changes while suppressing noise', function (): void {
    $activity = new ActivityLog;
    $activity->subject_type = 'App\\Models\\Order';
    $activity->event = 'updated';
    $activity->setAttribute('properties', []);
    $resource = fopen('php://memory', 'r');

    try {
        $details = app(ActivityDiffBuilder::class)->build($activity, [
            'attributes' => [
                'active' => true,
                'count' => 12,
                'metadata' => ['new' => 'value'],
                'unrenderable' => $resource,
                'note' => 'Added',
                'same' => 'unchanged',
                'updated_at' => '2026-08-02 12:00:00',
            ],
            'old' => [
                'active' => false,
                'count' => 3,
                'metadata' => ['old' => 'value'],
                'unrenderable' => 'Before',
                'same' => 'unchanged',
                'updated_at' => '2026-08-01 12:00:00',
                'removed' => 'Legacy',
            ],
        ])->keyBy('key');

        expect($details)->not->toHaveKeys(['same', 'updated_at'])
            ->and($details->get('active')?->old)->toBe('false')
            ->and($details->get('active')?->new)->toBe('true')
            ->and($details->get('count')?->old)->toBe('3')
            ->and($details->get('count')?->new)->toBe('12')
            ->and($details->get('metadata')?->old)->toBe('{"old":"value"}')
            ->and($details->get('metadata')?->new)->toBe('{"new":"value"}')
            ->and($details->get('unrenderable')?->new)->toBe('')
            ->and($details->get('note')?->old)->toBeNull()
            ->and($details->get('removed')?->new)->toBeNull();
    } finally {
        if (is_resource($resource)) {
            fclose($resource);
        }
    }
});

test('headline rendering degrades incomplete change payloads and summarizes multi-field updates', function (): void {
    $activity = new ActivityLog;
    $activity->subject_type = 'App\\Models\\Order';
    $activity->event = 'updated';
    $activity->setAttribute('properties', []);
    $renderer = app(HeadlineRenderer::class);
    $changes = collect([
        new ActivityChangeDetail(
            key: 'name',
            label: 'Name',
            old: 'Before',
            new: 'After',
            description: 'Name changed',
        ),
        new ActivityChangeDetail(
            key: 'status',
            label: 'Status',
            old: 'Draft',
            new: 'Published',
            description: 'Status changed',
        ),
    ]);

    $multiple = $renderer->resolveHeadline('updated', $activity, 'Ada', 7, $changes);
    $activity->event = 'status_changed';
    $activity->setAttribute('properties', ['attributes' => ['status' => new stdClass]]);
    $missingStatus = $renderer->resolveHeadline('status_changed', $activity, 'Ada', 7, $changes->take(0));

    $fieldSegment = collect($multiple->segments)->firstWhere('type', HeadlineSegmentType::Field);

    expect($renderer->buildSummary(2, null))->toContain('2')
        ->and($renderer->buildChangedText('', null, null))->not->toBe('')
        ->and($multiple->headline)->toContain('Name, Status')
        ->and($fieldSegment?->text)->toBe('Name, Status')
        ->and($missingStatus->headline)->not->toContain(':status');
});

test('label resolution handles structured values and all canonical status payload shapes', function (): void {
    $mapping = new TestActivityMapping(TestActivitySubjectWithHasModelActivity::class);
    $registry = new MappingRegistry;
    $registry->register($mapping);
    $resolver = new LabelResolver($registry);
    $activity = new ActivityLog;
    $activity->subject_type = TestActivitySubjectWithHasModelActivity::class;
    $activity->event = 'status_changed';

    $activity->setAttribute('properties', ['attributes' => ['status' => 'draft']]);
    $mappedStatus = $resolver->resolveNewStatusLabel($activity);

    $activity->setAttribute('properties', ['to_status' => new stdClass]);
    $invalidStatus = $resolver->resolveNewStatusLabel($activity);

    $activity->setAttribute('properties', ['to_status' => '   ']);
    $blankStatus = $resolver->resolveNewStatusLabel($activity);

    expect($resolver->resolveFieldValue('metadata', ['nested' => true], $activity))
        ->toBe(['nested' => true])
        ->and($mappedStatus)->toBe('Mapped value')
        ->and($invalidStatus)->toBeNull()
        ->and($blankStatus)->toBeNull();
});
