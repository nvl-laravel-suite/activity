<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Activity\Enums\ActivityImportance;
use Nvl\Activity\Enums\ActivitySource;
use Nvl\Activity\Enums\ActivityVisibility;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityDiffBuilder;
use Nvl\Activity\Services\ActivityReader;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Services\HeadlineRenderer;
use Nvl\Activity\Services\LabelResolver;
use Nvl\Activity\Services\MappingRegistry;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Activity\Support\ModelKeyIdentifierValidator;
use Nvl\Activity\Tests\Stubs\TestActivityMapping;
use Nvl\Activity\Tests\Stubs\TestActivitySubjectWithHasModelActivity;
use Nvl\Activity\Tests\Stubs\TestUuidActivitySubject;
use Nvl\Support\Enums\SystemActor;
use RuntimeException as ActivityTestRuntimeException;

test('native metadata retains consumer keys and null values while rejecting malformed records', function (): void {
    $record = app(ActivityRecorder::class)->record(['event' => 'reviewed', 'context' => ['consumer_flag' => true, 'nullable' => null]], SystemActor::Background);
    expect($record->properties->get('context'))->toBe(['consumer_flag' => true, 'nullable' => null]);
    expect(fn () => app(ActivityRecorder::class)->record(['event' => 'reviewed', 'context' => 'not-a-record'], SystemActor::Background))->toThrow(InvalidArgumentException::class);
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

    $filters = ([
        'search' => 'audit target',
        'event' => 'updated',
        'causer_id' => '7',
        'subject_type' => 'App\\Models\\Order',
        'subject_id' => '42',
        'created_at_from' => '2026-07-01',
        'created_at_to' => '2026-07-31',
    ]);

    $filtered = ActivityLog::query()->forIndex($filters)->get();
    $legacyDirectFilter = ActivityLog::query()->forIndex(['search' => null, 'event' => 'created', 'causer_id' => null, 'created_at_from' => null, 'created_at_to' => null])->get();
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
    $reference = new ActivitySubjectReference('records', 'one');
    foreach ([['created', 'one', null, '2026-07-01 12:00:00'], ['reviewed', 'two', 'one', '2026-07-02 12:00:00']] as [$event,$subject,$causer,$date]) {
        ActivityLog::query()->create(['description' => $event, 'event' => $event, 'subject_type' => 'records', 'subject_id' => $subject, 'causer_type' => 'records', 'causer_id' => $causer, 'created_at' => $date]);
    }
    $reader = app(ActivityReader::class);
    expect($reader->paginate(['subject_or_causer' => $reference], SystemActor::Background)->total())->toBe(2)
        ->and($reader->paginate(['created_at_from' => '2026-07-02', 'created_at_to' => '2026-07-03'], SystemActor::Background)->total())->toBe(1)
        ->and($reader->timeline($reference, SystemActor::Background)->pluck('event')->all())->toBe(['created']);
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

    $service = app(ActivityReader::class);
    $single = $service->paginate(['subject_type' => 'type-a', 'subject_id' => '1', 'per_page' => 100], SystemActor::Background);
    $multiple = $service->paginate(['subjects' => [
        new ActivitySubjectReference(' type-a ', '1'),
        new ActivitySubjectReference('type-b', 2),
        new ActivitySubjectReference('type-a', 1),
    ], 'per_page' => 100], SystemActor::Background);

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
    $page = app(ActivityReader::class)->paginate(['subjects' => [
        new ActivitySubjectReference('1', 'resource-1'),
    ], 'per_page' => 20], SystemActor::Background);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($page->total())->toBe(1)
        ->and($queries)->not->toBeEmpty();

    foreach (array_filter($queries, static fn (array $query): bool => str_contains($query['query'], '"subject_type" = ?')) as $query) {
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
    'read type boundary' => fn () => app(ActivityReader::class)
        ->paginate(['subjects' => [new ActivitySubjectReference("type\0", 'resource-1')], 'per_page' => 20], SystemActor::Background),
    'record identifier body' => fn () => app(ActivityRecorder::class)
        ->record(['subject' => new ActivitySubjectReference('type', "resource\0-1"), 'event' => 'updated'], SystemActor::Background),
]);

test('multi-subject history rejects oversized inputs and returns an empty page for an empty reference list', function (): void {
    $reader = app(ActivityReader::class);
    $subjects = array_map(fn (int $id) => new ActivitySubjectReference('records', $id), range(1, 101));
    expect(fn () => $reader->paginate(['subjects' => $subjects], SystemActor::Background))->toThrow(InvalidArgumentException::class);
    ActivityLog::query()->create(['description' => 'existing', 'event' => 'created']);
    expect($reader->paginate(['subjects' => []], SystemActor::Background)->total())->toBe(0);
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
    Schema::create('mutable_activity_subjects', function (Blueprint $table): void {
        $table->id();
        $table->string('description');
        $table->timestamp('updated_at')->nullable();
    });
    $subject = new class extends Model
    {
        protected $table = 'mutable_activity_subjects';

        protected $guarded = [];

        public $timestamps = false;
    };
    $subject->fill(['description' => 'Before'])->save();
    $subject->timestamps = false;
    $subject->forceFill([
        'description' => 'After',
        'updated_at' => CarbonImmutable::parse('2026-08-02 12:00:00'),
    ])->save();

    $recorder = app(ActivityRecorder::class);
    $recorded = $recorder->record(['subject' => $subject, 'event' => 'updated', 'description' => ' ', 'visibility' => ActivityVisibility::AuditOnly, 'importance' => ActivityImportance::Important], SystemActor::Background);

    $subject->syncChanges();
    $unchanged = $recorder->record(['subject' => $subject, 'event' => 'updated', 'description' => 'No model changes'], SystemActor::Background);
    $nonDiff = $recorder->record(['subject' => $subject, 'event' => 'viewed', 'description' => 'Viewed'], SystemActor::Background);
    $explicit = $recorder->record(['subject' => $subject, 'event' => 'status_transition', 'description' => 'Status changed', 'attributes' => ['status' => 'published'], 'old' => ['status' => 'draft'], 'resolve_changes' => false], SystemActor::Background);

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
        ->and($recorded->properties?->get('source'))->toBe(ActivitySource::System->value)
        ->and($recorded->properties?->get('visibility'))->toBe(ActivityVisibility::AuditOnly->value)
        ->and($recorded->properties?->get('importance'))->toBe(ActivityImportance::Important->value)
        ->and($recorded->attribute_changes?->get('attributes'))->toBe(['description' => 'After'])
        ->and($recorded->attribute_changes?->get('old'))->toBe(['description' => 'Before'])
        ->and($unchanged->attribute_changes?->get('attributes'))->toBe([])
        ->and($nonDiff->attribute_changes?->get('attributes'))->toBe([])
        ->and($explicit->attribute_changes?->get('attributes'))->toBe(['status' => 'published'])
        ->and($explicit->attribute_changes?->get('old'))->toBe(['status' => 'draft']);
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
            ->and(($details->get('active')['old'] ?? null))->toBe('false')
            ->and(($details->get('active')['new'] ?? null))->toBe('true')
            ->and(($details->get('count')['old'] ?? null))->toBe('3')
            ->and(($details->get('count')['new'] ?? null))->toBe('12')
            ->and(($details->get('metadata')['old'] ?? null))->toBe('{"old":"value"}')
            ->and(($details->get('metadata')['new'] ?? null))->toBe('{"new":"value"}')
            ->and(($details->get('unrenderable')['new'] ?? null))->toBe('')
            ->and(($details->get('note')['old'] ?? null))->toBeNull()
            ->and(($details->get('removed')['new'] ?? null))->toBeNull();
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
        ['key' => 'name', 'label' => 'Name', 'old' => 'Before', 'new' => 'After', 'description' => 'Name changed'],
        ['key' => 'status', 'label' => 'Status', 'old' => 'Draft', 'new' => 'Published', 'description' => 'Status changed'],
    ]);

    $multiple = $renderer->resolveHeadline('updated', $activity, 'Ada', 7, $changes);
    $activity->event = 'status_changed';
    $activity->setAttribute('properties', ['attributes' => ['status' => new stdClass]]);
    $missingStatus = $renderer->resolveHeadline('status_changed', $activity, 'Ada', 7, $changes->take(0));

    expect($renderer->buildSummary(2, null))->toContain('2')
        ->and($renderer->buildChangedText('', null, null))->not->toBe('')
        ->and($multiple)->toContain('Name, Status')
        ->and($missingStatus)->not->toContain(':status');
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
