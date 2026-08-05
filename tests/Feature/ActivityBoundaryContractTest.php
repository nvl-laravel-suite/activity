<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Nvl\Activity\Data\Display\ActivityItem;
use Nvl\Activity\Data\Display\ActivityItemProperties;
use Nvl\Activity\Data\Display\HeadlineSegment;
use Nvl\Activity\Enums\ActivityResponseCode;
use Nvl\Activity\Enums\EntrySource;
use Nvl\Activity\Enums\HeadlineSegmentType;
use Nvl\Activity\Exceptions\ActivityConfigurationException;
use Nvl\Activity\Exceptions\ActivityPurgeCriteriaException;
use Nvl\Activity\Exceptions\ActivityRecordingException;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Policies\ActivityLogPolicy;
use Nvl\Activity\Services\ActivityEntryNormalizer;
use Nvl\Activity\Services\ActivityRelationLoader;
use Nvl\Activity\Services\ActivitySubjectTimelineResolver;
use Nvl\Activity\Services\ActivityTransformService;
use Nvl\Activity\Services\ModelActivityTimelineService;
use Nvl\Activity\Services\TimelineFilter;
use Nvl\Activity\Support\CauserNormalizer;
use Nvl\Activity\Support\TimelineActivityRules;
use Nvl\Activity\Tests\Stubs\AbstractTestActivitySubject;
use Nvl\Activity\Tests\Stubs\TestActivityCauser;
use Nvl\Activity\Tests\Stubs\TestActivityTimelineSubject;
use Nvl\Activity\Tests\Stubs\TestActivityUser;
use Nvl\Activity\Tests\Stubs\TestSoftDeletedActivityCauser;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

test('causer normalization honors configured display attributes and scalar actor metadata', function (): void {
    config()->set('activity.causer_suggestions.label_attribute', 'display_name');
    config()->set('activity.causer_suggestions.sublabel_attribute', 'contact');

    $causer = new TestActivityCauser;
    $causer->forceFill([
        'causer_key' => 42,
        'display_name' => 'Grace Hopper',
        'contact' => 'grace@example.test',
    ]);
    $normalizer = new CauserNormalizer;

    expect($normalizer->normalize($causer))->toBe([
        'id' => 42,
        'name' => 'Grace Hopper',
        'email' => 'grace@example.test',
    ])->and($normalizer->normalize(null, ['actor_id' => 'operator-1']))->toBe([
        'id' => 'operator-1',
        'name' => null,
        'email' => null,
    ]);
});

test('display reads support causer tables without name or email columns', function (): void {
    Schema::create('activity_test_causers', function (Blueprint $table): void {
        $table->increments('causer_key');
        $table->string('display_name');
        $table->string('contact')->nullable();
    });

    try {
        config()->set('activity.causer_suggestions.label_attribute', 'display_name');
        config()->set('activity.causer_suggestions.sublabel_attribute', 'contact');
        $causer = TestActivityCauser::query()->create([
            'display_name' => 'Custom operator',
            'contact' => 'operator@example.test',
        ]);
        ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'Reviewed',
            'event' => 'reviewed',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => $causer->getKey(),
        ]);

        $activity = ActivityLog::forDisplay()->firstOrFail();
        $item = app(ActivityTransformService::class)->normalizeActivity($activity);

        expect($item->causer?->name)->toBe('Custom operator')
            ->and($item->causer?->email)->toBe('operator@example.test');
    } finally {
        Schema::dropIfExists('activity_test_causers');
    }
});

test('display reads preserve soft deleted historical causers', function (): void {
    Schema::create('activity_soft_deleted_causers', function (Blueprint $table): void {
        $table->id();
        $table->string('display_name');
        $table->string('contact')->nullable();
        $table->softDeletes();
    });

    try {
        config()->set('activity.causer_suggestions.label_attribute', 'display_name');
        config()->set('activity.causer_suggestions.sublabel_attribute', 'contact');
        $causer = TestSoftDeletedActivityCauser::query()->create([
            'display_name' => 'Historical operator',
            'contact' => 'history@example.test',
        ]);
        $activity = ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'Reviewed',
            'event' => 'reviewed',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => $causer->getKey(),
        ]);
        $causer->delete();

        $item = app(ActivityTransformService::class)->normalizeActivity($activity);

        expect($item->causer?->name)->toBe('Historical operator')
            ->and($item->causer?->email)->toBe('history@example.test');
    } finally {
        Schema::dropIfExists('activity_soft_deleted_causers');
    }
});

test('display relations use each related model connection when activity storage is separate', function (): void {
    $storageConnection = 'activity_relation_storage';
    $storageTable = 'activity_relation_log';
    $originalConnection = config('activity.storage.connection');
    $originalTable = config('activity.storage.table');

    config()->set("database.connections.{$storageConnection}", [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('activity.storage.connection', $storageConnection);
    config()->set('activity.storage.table', $storageTable);

    Schema::create('activity_test_causers', function (Blueprint $table): void {
        $table->increments('causer_key');
        $table->string('display_name');
    });
    Schema::create('activity_timeline_subjects', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    Schema::connection($storageConnection)->create($storageTable, function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('log_name')->nullable();
        $table->text('description');
        $table->string('subject_type')->nullable();
        $table->string('subject_id')->nullable();
        $table->string('event')->nullable();
        $table->string('causer_type')->nullable();
        $table->string('causer_id')->nullable();
        $table->json('attribute_changes')->nullable();
        $table->json('properties')->nullable();
        $table->uuid('batch_uuid')->nullable();
        $table->timestamps();
    });

    try {
        $causer = TestActivityCauser::query()->create([
            'display_name' => 'Default connection operator',
        ]);
        $subject = TestActivityTimelineSubject::query()->create([
            'name' => 'Default connection subject',
        ]);
        $activity = ActivityLog::query()->create([
            'log_name' => 'separate-storage',
            'description' => 'Cross-connection activity',
            'event' => 'created',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => $causer->getKey(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);

        app(ActivityRelationLoader::class)->load(new EloquentCollection([$activity]));

        $loadedCauser = $activity->getRelation('causer');
        $loadedSubject = $activity->getRelation('subject');

        expect($activity->getConnectionName())->toBe($storageConnection)
            ->and($loadedCauser)->toBeInstanceOf(TestActivityCauser::class)
            ->and($loadedCauser->is($causer))->toBeTrue()
            ->and($loadedSubject)->toBeInstanceOf(TestActivityTimelineSubject::class)
            ->and($loadedSubject->is($subject))->toBeTrue();
    } finally {
        config()->set('activity.storage.connection', $originalConnection);
        config()->set('activity.storage.table', $originalTable);
        Schema::dropIfExists('activity_timeline_subjects');
        Schema::dropIfExists('activity_test_causers');
        DB::purge($storageConnection);
        config()->set("database.connections.{$storageConnection}", null);
    }
});

test('display reads degrade stale polymorphic model types without failing the feed', function (): void {
    $activity = ActivityLog::query()->create([
        'log_name' => 'test',
        'description' => 'Historical row',
        'event' => 'created',
        'subject_type' => 'Removed\\Domain\\HistoricalRecord',
        'subject_id' => 'record-1',
        'causer_type' => 'Removed\\Domain\\HistoricalUser',
        'causer_id' => 'user-1',
    ]);

    $item = app(ActivityTransformService::class)->normalizeActivity($activity);
    $collectionItems = app(ActivityTransformService::class)->normalizeActivities(collect([$activity->fresh()]));

    expect($item->subjectLabel)->toBe('Historical Record')
        ->and($item->causer?->id)->toBeNull()
        ->and($item->headline)->not->toBeEmpty()
        ->and($collectionItems)->toHaveCount(1)
        ->and($collectionItems[0]->subjectLabel)->toBe('Historical Record');
});

test('display reads degrade unavailable concrete polymorphic model types', function (): void {
    $activity = ActivityLog::query()->create([
        'log_name' => 'test',
        'description' => 'Historical row',
        'event' => 'created',
        'subject_type' => AbstractTestActivitySubject::class,
        'subject_id' => 'record-1',
    ]);

    $item = app(ActivityTransformService::class)->normalizeActivity($activity);

    expect($item->subjectLabel)->toBe('Abstract Test Activity Subject')
        ->and($item->headline)->not->toBeEmpty();
});

test('authorization uses cacheable named abilities and fails closed when missing', function (): void {
    $user = new TestActivityUser;
    $user->forceFill(['id' => 1]);
    $subject = new TestActivityTimelineSubject;
    $policy = new ActivityLogPolicy;

    expect($policy->viewAny($user))->toBeFalse()
        ->and($policy->viewTimeline($user, $subject))->toBeFalse()
        ->and($policy->delete($user))->toBeFalse();

    config()->set('activity.authorization.abilities', [
        'view' => 'activity.view',
        'timeline' => 'activity.timeline',
        'purge' => 'activity.purge',
    ]);
    Gate::define('activity.view', static fn (TestActivityUser $actor): bool => $actor->getKey() === 1);
    Gate::define(
        'activity.timeline',
        static fn (TestActivityUser $actor, TestActivityTimelineSubject $timelineSubject): bool => $actor->getKey() === 1
            && $timelineSubject->getTable() === 'activity_timeline_subjects',
    );
    Gate::define('activity.purge', static fn (TestActivityUser $actor): bool => $actor->getKey() === 1);

    expect($policy->viewAny($user))->toBeTrue()
        ->and($policy->viewTimeline($user, $subject))->toBeTrue()
        ->and($policy->delete($user))->toBeTrue();
});

test('timeline resolution requires an explicit subject allowlist', function (): void {
    config()->set('activity.routes.timeline_subjects', []);

    expect(fn () => app(ActivitySubjectTimelineResolver::class)->resolve(
        TestActivityTimelineSubject::class,
        '1',
    ))->toThrow(ValidationException::class);
});

test('timeline resolution accepts explicitly allowed host models', function (): void {
    Schema::create('activity_timeline_subjects', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    try {
        $subject = TestActivityTimelineSubject::query()->create(['name' => 'Allowed']);
        config()->set('activity.routes.timeline_subjects', [TestActivityTimelineSubject::class]);

        $resolved = app(ActivitySubjectTimelineResolver::class)->resolve(
            TestActivityTimelineSubject::class,
            (string) $subject->getKey(),
        );

        expect($resolved->is($subject))->toBeTrue();
    } finally {
        Schema::dropIfExists('activity_timeline_subjects');
    }
});

test('generic capture rules suppress only explicitly configured technical attributes', function (): void {
    expect(TimelineActivityRules::isNoisyChangeKey('updated_at'))->toBeTrue()
        ->and(TimelineActivityRules::isNoisyChangeKey('expires_at'))->toBeFalse()
        ->and(TimelineActivityRules::isNoisyChangeKey('customer_id'))->toBeFalse();

    config()->set('activity.capture.ignored_attributes', ['revision']);

    expect(TimelineActivityRules::isNoisyChangeKey('updated_at'))->toBeFalse()
        ->and(TimelineActivityRules::isNoisyChangeKey('revision'))->toBeTrue();
});

test('normalization never infers consumer events from descriptions', function (): void {
    $activity = new ActivityLog;
    $activity->forceFill([
        'id' => 'activity-1',
        'description' => 'Customer deleted after payment message sent',
        'event' => null,
        'properties' => [],
        'attribute_changes' => [],
    ]);
    $activity->setRelation('causer', null);
    $activity->setRelation('subject', null);

    expect(app(ActivityEntryNormalizer::class)->normalize($activity)->event)
        ->toBe('activity_logged');
});

test('explicit timeline visibility admits consumer-defined system events', function (): void {
    $item = new ActivityItem(
        id: 'activity-1',
        log: 'consumer',
        event: 'consumer_semantic_event',
        source: EntrySource::ActivityLog,
        properties: ActivityItemProperties::fromPayload([
            'source' => 'system',
            'visibility' => 'timeline',
        ]),
    );

    expect((new TimelineFilter)->shouldIncludeInSignalTimeline($item))->toBeTrue();
});

test('timeline reads fall back safely when a custom activity model relation is preloaded', function (): void {
    Schema::create('activity_timeline_subjects', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    try {
        $subject = TestActivityTimelineSubject::query()->create(['name' => 'Custom activity host']);
        ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'Fallback activity',
            'event' => 'created',
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (string) $subject->getKey(),
        ]);
        $subject->setRelation(
            'activitiesAsSubject',
            new EloquentCollection([new SpatieActivity]),
        );

        $timeline = app(ModelActivityTimelineService::class)->forSubject($subject);

        expect($timeline)->toHaveCount(1)
            ->and($timeline[0]->event)->toBe('created');
    } finally {
        Schema::dropIfExists('activity_timeline_subjects');
    }
});

test('activity exceptions expose stable scoped metadata without leaking diagnostics', function (): void {
    $configuration = ActivityConfigurationException::emptyTableName();
    $recording = ActivityRecordingException::invalidBatchIdentifier();
    $purge = ActivityPurgeCriteriaException::invalidDate(
        'before',
        new InvalidArgumentException('diagnostic detail'),
    );

    expect($configuration->responseCode())->toBe(ActivityResponseCode::InvalidConfiguration->value)
        ->and($configuration->suggestedStatus())->toBe(500)
        ->and($configuration->publicContext())->toBe([])
        ->and($configuration->context())->toBe(['configuration' => 'activity.storage.table'])
        ->and($recording->responseCode())->toBe(ActivityResponseCode::InvalidBatchIdentifier->value)
        ->and($recording->suggestedStatus())->toBe(422)
        ->and($recording->publicContext())->toBe(['field' => 'batch_uuid'])
        ->and($recording->context())->toBe([])
        ->and($purge->responseCode())->toBe(ActivityResponseCode::InvalidPurgeCriteria->value)
        ->and($purge->publicContext())->toBe(['option' => 'before'])
        ->and($purge->getPrevious()?->getMessage())->toBe('diagnostic detail');
});

test('headline segment enum stays strongly typed while serializing to its api value', function (): void {
    $segment = new HeadlineSegment(
        type: HeadlineSegmentType::Status,
        text: 'Published',
    );

    expect($segment->type)->toBe(HeadlineSegmentType::Status)
        ->and($segment->toArray())->toMatchArray([
            'type' => 'status',
            'text' => 'Published',
        ]);
});
