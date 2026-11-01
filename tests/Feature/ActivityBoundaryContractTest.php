<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Contracts\ActivityAuthorization;
use Nvl\Activity\Enums\ActivityResponseCode;
use Nvl\Activity\Enums\ActivityVisibility;
use Nvl\Activity\Exceptions\ActivityConfigurationException;
use Nvl\Activity\Exceptions\ActivityPurgeCriteriaException;
use Nvl\Activity\Exceptions\ActivityRecordingException;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityReader;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Services\ActivityRelationLoader;
use Nvl\Activity\Services\LabelResolver;
use Nvl\Activity\Services\TimelineFilter;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Activity\Support\CauserNormalizer;
use Nvl\Activity\Support\TimelineActivityRules;
use Nvl\Activity\Tests\Stubs\AbstractTestActivitySubject;
use Nvl\Activity\Tests\Stubs\TestActivityCauser;
use Nvl\Activity\Tests\Stubs\TestActivityTimelineSubject;
use Nvl\Activity\Tests\Stubs\TestSoftDeletedActivityCauser;
use Nvl\Support\Enums\SystemActor;

test('native activity mass assignment rejects undeclared host columns', function (): void {
    $previous = ActivityLog::preventsSilentlyDiscardingAttributes();
    ActivityLog::preventSilentlyDiscardingAttributes(true);

    try {
        expect(fn () => new ActivityLog(['description' => 'Declared fact', 'unregistered_host_column' => 'unexpected']))
            ->toThrow(MassAssignmentException::class);
        $activity = new ActivityLog(['description' => 'Declared fact', 'properties' => ['source' => 'system']]);
        expect($activity->description)->toBe('Declared fact')
            ->and($activity->properties?->get('source'))->toBe('system');
    } finally {
        ActivityLog::preventSilentlyDiscardingAttributes($previous);
    }
});

test('causer normalization honors configured display attributes and scalar actor metadata', function (): void {
    config()->set('nvl-activity.causer_suggestions.label_attribute', 'display_name');
    config()->set('nvl-activity.causer_suggestions.sublabel_attribute', 'contact');

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
        $table->string('name');
        $table->string('email')->nullable();
    });

    try {
        config()->set('nvl-activity.causer_suggestions.label_attribute', 'name');
        config()->set('nvl-activity.causer_suggestions.sublabel_attribute', 'email');
        $causer = TestActivityCauser::query()->create([
            'name' => 'Custom operator',
            'email' => 'operator@example.test',
        ]);
        ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'Reviewed',
            'event' => 'reviewed',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => $causer->getKey(),
        ]);

        $activity = ActivityLog::forDisplay()->firstOrFail();
        app(ActivityRelationLoader::class)->load(new EloquentCollection([$activity]));
        $item = $activity;

        expect($item->causer?->name)->toBe('Custom operator')
            ->and($item->causer?->email)->toBe('operator@example.test');
    } finally {
        Schema::dropIfExists('activity_test_causers');
    }
});

test('display reads preserve soft deleted historical causers', function (): void {
    Schema::create('activity_soft_deleted_causers', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->softDeletes();
    });

    try {
        config()->set('nvl-activity.causer_suggestions.label_attribute', 'name');
        config()->set('nvl-activity.causer_suggestions.sublabel_attribute', 'email');
        $causer = TestSoftDeletedActivityCauser::query()->create([
            'name' => 'Historical operator',
            'email' => 'history@example.test',
        ]);
        $activity = ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'Reviewed',
            'event' => 'reviewed',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => $causer->getKey(),
        ]);
        $causer->delete();

        app(ActivityRelationLoader::class)->load(new EloquentCollection([$activity]));
        $item = $activity;

        expect($item->causer?->name)->toBe('Historical operator')
            ->and($item->causer?->email)->toBe('history@example.test');
    } finally {
        Schema::dropIfExists('activity_soft_deleted_causers');
    }
});

test('display relations use each related model connection when activity storage is separate', function (): void {
    $storageConnection = 'activity_relation_storage';
    $storageTable = 'activity_relation_log';
    $originalConnection = config('nvl-activity.connection');
    $originalTable = config('nvl-activity.tables.log');

    config()->set("database.connections.{$storageConnection}", [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('nvl-activity.connection', $storageConnection);
    config()->set('nvl-activity.tables.log', $storageTable);

    Schema::create('activity_test_causers', function (Blueprint $table): void {
        $table->increments('causer_key');
        $table->string('name');
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
            'name' => 'Default connection operator',
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
        config()->set('nvl-activity.connection', $originalConnection);
        config()->set('nvl-activity.tables.log', $originalTable);
        Schema::dropIfExists('activity_timeline_subjects');
        Schema::dropIfExists('activity_test_causers');
        DB::purge($storageConnection);
        config()->set("database.connections.{$storageConnection}", null);
    }
});

test('display reads degrade stale polymorphic model types without failing the feed', function (): void {
    $activity = ActivityLog::query()->create(['description' => 'Historical row', 'event' => 'created', 'subject_type' => 'Removed\\Domain\\HistoricalRecord', 'subject_id' => 'one', 'causer_type' => 'Removed\\Domain\\HistoricalUser', 'causer_id' => 'one']);
    app(ActivityRelationLoader::class)->load(new EloquentCollection([$activity]));
    expect($activity->getRelation('subject'))->toBeNull()->and($activity->getRelation('causer'))->toBeNull()
        ->and(app(LabelResolver::class)->resolveSubjectLabel($activity))->toBe('Historical Record');
});

test('display reads degrade unavailable concrete polymorphic model types', function (): void {
    $activity = ActivityLog::query()->create(['description' => 'Historical row', 'event' => 'created', 'subject_type' => AbstractTestActivitySubject::class, 'subject_id' => 'one']);
    app(ActivityRelationLoader::class)->load(new EloquentCollection([$activity]));
    expect($activity->getRelation('subject'))->toBeNull()->and(app(LabelResolver::class)->resolveSubjectLabel($activity))->toBe('Abstract Test Activity Subject');
});

test('native service authorization fails closed without a host policy', function (): void {
    app()->offsetUnset(ActivityAuthorization::class);
    expect(fn () => app(ActivityReader::class)->paginate([], SystemActor::Background))->toThrow(AuthorizationException::class);
});

test('model-free timelines never dynamically construct their historical subject type', function (): void {
    $reference = new ActivitySubjectReference('Removed\\UntrustedModel', 'one');
    expect(app(ActivityReader::class)->timeline($reference, SystemActor::Background))->toBeEmpty();
});

test('native timeline accepts an explicitly resolved persisted host model', function (): void {
    Schema::create('activity_timeline_subjects', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    $subject = TestActivityTimelineSubject::query()->create(['name' => 'Allowed']);
    app(ActivityRecorder::class)->record(['subject' => $subject, 'event' => 'created'], SystemActor::Background);
    expect(app(ActivityReader::class)->timeline($subject, SystemActor::Background))->toHaveCount(1);
});

test('generic capture rules suppress only explicitly configured technical attributes', function (): void {
    expect(TimelineActivityRules::isNoisyChangeKey('updated_at'))->toBeTrue()
        ->and(TimelineActivityRules::isNoisyChangeKey('expires_at'))->toBeFalse()
        ->and(TimelineActivityRules::isNoisyChangeKey('customer_id'))->toBeFalse();

    config()->set('nvl-activity.capture.ignored_attributes', ['revision']);

    expect(TimelineActivityRules::isNoisyChangeKey('updated_at'))->toBeFalse()
        ->and(TimelineActivityRules::isNoisyChangeKey('revision'))->toBeTrue();
});

test('native timeline never guesses semantic events from descriptions', function (): void {
    $activity = new ActivityLog(['description' => 'Customer deleted after payment', 'event' => null, 'properties' => []]);
    expect((new TimelineFilter)->shouldIncludeInSignalTimeline($activity))->toBeTrue()->and($activity->semanticEvent())->toBe('activity_logged')->and($activity->event)->toBeNull();
});

test('explicit timeline visibility admits consumer-defined system events', function (): void {
    $item = new ActivityLog(['event' => 'consumer_semantic_event', 'properties' => ['source' => 'system', 'visibility' => 'timeline']]);

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
            new EloquentCollection([new ActivityLog]),
        );

        $timeline = app(ActivityReader::class)->timeline($subject, SystemActor::Background, 1000);

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
        ->and($configuration->context())->toBe(['configuration' => 'nvl-activity.tables.log'])
        ->and($recording->responseCode())->toBe(ActivityResponseCode::InvalidBatchIdentifier->value)
        ->and($recording->suggestedStatus())->toBe(422)
        ->and($recording->publicContext())->toBe(['field' => 'batch_uuid'])
        ->and($recording->context())->toBe([])
        ->and($purge->responseCode())->toBe(ActivityResponseCode::InvalidPurgeCriteria->value)
        ->and($purge->publicContext())->toBe(['option' => 'before'])
        ->and($purge->getPrevious()?->getMessage())->toBe('diagnostic detail');
});

test('native visibility enums persist their exact domain value without presentation segments', function (): void {
    $visibility = ActivityVisibility::Timeline;
    $record = app(ActivityRecorder::class)->record(['event' => 'reviewed', 'visibility' => $visibility], SystemActor::Background);
    expect($record->properties->get('visibility'))->toBe($visibility->value);
});

test('historical visibility only admits absent or blank strings and exact timeline values', function (mixed $visibility, bool $included): void {
    $item = new ActivityLog(['event' => 'created', 'properties' => ['visibility' => $visibility]]);

    expect((new TimelineFilter)->shouldIncludeInSignalTimeline($item))->toBe($included);
})->with([
    'null' => [null, true],
    'empty string' => ['', true],
    'blank string' => ['  ', true],
    'timeline' => ['timeline', true],
    'audit only' => ['audit_only', false],
    'unknown' => ['unknown', false],
    'list' => [['audit_only'], false],
    'object' => [['policy' => 'internal'], false],
    'empty array' => [[], false],
    'false' => [false, false],
    'true' => [true, false],
    'zero' => [0, false],
]);
