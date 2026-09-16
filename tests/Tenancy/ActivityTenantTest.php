<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Actions\Activity\ListActivityCauserSuggestionsAction;
use Nvl\Activity\Actions\Activity\QueueActivityLogPurgeAction;
use Nvl\Activity\Exceptions\ActivityTimelineException;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityReadService;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Services\ActivityRelationLoader;
use Nvl\Activity\Services\ActivitySubjectTimelineResolver;
use Nvl\Activity\Services\MappingRegistry;
use Nvl\Activity\Services\ModelActivityTimelineService;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Activity\Tenancy\ActivityAdoptionAdapter;
use Nvl\Activity\Tests\Fixtures\ActivityHostAdoptionAdapter;
use Nvl\Activity\Tests\Fixtures\ActivitySettingChanged;
use Nvl\Activity\Tests\Fixtures\GlobalActivityCauser;
use Nvl\Activity\Tests\Fixtures\RecordActivitySettingChanged;
use Nvl\Activity\Tests\Fixtures\TenantActivitySubject;
use Nvl\Activity\Tests\Fixtures\TenantScenario;
use Nvl\Activity\Tests\Fixtures\UnknownActivitySubject;
use Nvl\Activity\Tests\Stubs\TestActivityMapping;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantContextMissing;
use Nvl\Tenancy\Exceptions\TenantSchemaNotReady;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

test('selected adoption adds the immutable ownership schema and refuses nonempty legacy storage', function (): void {
    $schema = $this->app['db']->connection()->getSchemaBuilder();
    $columns = collect($schema->getColumns('activity_log'))->keyBy('name');

    expect(config('activity.migrations.enabled'))->toBeTrue()
        ->and($columns)->toHaveKeys(['tenant_id', 'ownership_key'])
        ->and($columns->get('ownership_key')['nullable'])->toBeFalse()
        ->and($schema->hasIndex('activity_log', 'activity_ownership_created_idx'))->toBeTrue();

    app(TenantRunner::class)->run(
        new TenantId(TenantScenario::A),
        fn () => app(ActivityRecorder::class)->record(null, 'legacy_fact'),
    );

    $plan = new TenantAdoptionPlan(
        id: 'nonempty-legacy-proof',
        connection: (string) config('database.default'),
        mappingHash: '',
        configurationHash: '',
    );

    expect(fn () => app(ActivityAdoptionAdapter::class)->prepare($plan))
        ->toThrow(TenantBoundaryViolation::class, 'explicit historical adoption workflow');
});

test('a shared subject reference does not merge tenant timelines', function (): void {
    $runner = app(TenantRunner::class);
    $subject = new ActivitySubjectReference('external_record', 'same-id');
    $record = fn () => app(ActivityRecorder::class)
        ->recordForSubjectReference($subject, 'updated', context: ['key' => 'safe']);
    $a = $runner->run(new TenantId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'), $record);
    $b = $runner->run(new TenantId('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'), $record);
    $rows = $runner->run(new TenantId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
        fn () => app(ActivityReadService::class)->forSubjectKey('external_record', 'same-id'));
    expect($rows->modelKeys())->toBe([$a->getKey()])->not->toContain($b->getKey());
});

test('unresolved recording and reads fail closed', function (): void {
    expect(fn () => app(ActivityRecorder::class)->record(null, 'system_event'))->toThrow(TenantContextMissing::class);
    expect(fn () => app(ActivityReadService::class)->latest())->toThrow(TenantContextMissing::class);
});

test('system facts and explicit platform facts occupy separate partitions', function (): void {
    $runner = app(TenantRunner::class);
    $a = $runner->run(new TenantId(TenantScenario::A), fn () => app(ActivityRecorder::class)->record(null, 'system_event'));
    $platform = $runner->platform(new PlatformOperation('activity-proof', 'test', 'pest'), fn () => app(ActivityRecorder::class)->record(null, 'platform_event'));
    $rows = $runner->run(new TenantId(TenantScenario::A), fn () => app(ActivityReadService::class)->latest());
    expect($a->tenant_id)->toBe(TenantScenario::A)
        ->and($a->ownership_key)->toBe('tenant:'.TenantScenario::A)
        ->and($platform->tenant_id)->toBeNull()->and($platform->ownership_key)->toBe('platform')
        ->and($rows->modelKeys())->toBe([$a->getKey()]);
});

test('automatic capture stamps rows and does not trust supplied subject ownership', function (): void {
    adoptActivityHost();
    $runner = app(TenantRunner::class);
    $a = $runner->run(new TenantId(TenantScenario::A), fn () => TenantActivitySubject::create(['name' => 'A', 'tenant_id' => TenantScenario::A]));
    $b = $runner->run(new TenantId(TenantScenario::B), fn () => TenantActivitySubject::create(['name' => 'B', 'tenant_id' => TenantScenario::B]));
    $b->tenant_id = TenantScenario::A;
    $runner->run(new TenantId(TenantScenario::A), function () use ($a, $b): void {
        $a->update(['name' => 'Updated A']);
        expect(app(ActivityReadService::class)->forSubject($a))->toHaveCount(2);
        expect(fn () => app(ActivityRecorder::class)->record($b, 'updated'))->toThrow(TenantBoundaryViolation::class);
        expect(fn () => app(ActivityReadService::class)->forSubject($b))->toThrow(TenantBoundaryViolation::class);
    });
});

test('hydration reloads the canonical subject and rejects a preloaded foreign event', function (): void {
    adoptActivityHost();
    $runner = app(TenantRunner::class);
    $a = $runner->run(new TenantId(TenantScenario::A), fn () => TenantActivitySubject::create(['name' => 'A', 'tenant_id' => TenantScenario::A]));
    $b = $runner->run(new TenantId(TenantScenario::B), fn () => TenantActivitySubject::create(['name' => 'B secret', 'tenant_id' => TenantScenario::B]));
    $foreign = $runner->run(new TenantId(TenantScenario::B), fn () => app(ActivityReadService::class)->forSubject($b)->first());
    $runner->run(new TenantId(TenantScenario::A), function () use ($a, $b, $foreign): void {
        $rows = app(ActivityReadService::class)->forSubject($a);
        $rows->first()->setRelation('subject', $b);
        app(ActivityRelationLoader::class)->load($rows);
        expect($rows->first()->subject->name)->toBe('A');
        expect(fn () => app(ActivityRelationLoader::class)->load(new Collection([$foreign])))
            ->toThrow(TenantBoundaryViolation::class);
    });
});

test('native subject relations cannot hydrate a foreign registered subject', function (): void {
    adoptActivityHost();
    $runner = app(TenantRunner::class);
    $owned = $runner->run(new TenantId(TenantScenario::A), fn () => TenantActivitySubject::create([
        'name' => 'A native subject',
        'tenant_id' => TenantScenario::A,
    ]));
    $foreign = $runner->run(new TenantId(TenantScenario::B), fn () => TenantActivitySubject::create([
        'name' => 'B native secret',
        'tenant_id' => TenantScenario::B,
    ]));
    $activity = $runner->run(
        new TenantId(TenantScenario::A),
        fn () => app(ActivityRecorder::class)->recordForSubjectReference(
            new ActivitySubjectReference($foreign->getMorphClass(), $foreign->getKey()),
            'native_subject_reference',
        ),
    );
    $ownedActivity = $runner->run(
        new TenantId(TenantScenario::A),
        fn () => app(ActivityRecorder::class)->record($owned, 'native_owned_subject'),
    );

    $runner->run(new TenantId(TenantScenario::A), function () use ($activity, $foreign, $owned, $ownedActivity): void {
        $lazy = ActivityLog::query()->findOrFail($activity->getKey());
        $explicit = ActivityLog::query()->findOrFail($activity->getKey());
        $eager = ActivityLog::query()->with('subject')->findOrFail($activity->getKey());
        $preloaded = ActivityLog::query()->findOrFail($ownedActivity->getKey());
        $preloaded->setRelation('subject', $foreign);

        expect($lazy->subject)->toBeNull()
            ->and($explicit->subject()->first())->toBeNull()
            ->and($eager->subject)->toBeNull()
            ->and($preloaded->subject->is($owned))->toBeTrue();
    });
});

test('native subject relations deny unknown types before model construction', function (): void {
    $runner = app(TenantRunner::class);
    $activity = $runner->run(
        new TenantId(TenantScenario::A),
        fn () => app(ActivityRecorder::class)->recordForSubjectReference(
            new ActivitySubjectReference(UnknownActivitySubject::class, 'unknown-native-id'),
            'unknown_native_subject',
        ),
    );
    UnknownActivitySubject::$constructed = 0;

    $runner->run(new TenantId(TenantScenario::A), function () use ($activity): void {
        $lazy = ActivityLog::query()->findOrFail($activity->getKey());
        $explicit = ActivityLog::query()->findOrFail($activity->getKey());

        expect(fn () => $lazy->subject)->toThrow(TenantBoundaryViolation::class)
            ->and(fn () => $explicit->subject()->first())->toThrow(TenantBoundaryViolation::class)
            ->and(fn () => ActivityLog::query()->with('subject')->findOrFail($activity->getKey()))
            ->toThrow(TenantBoundaryViolation::class)
            ->and(UnknownActivitySubject::$constructed)->toBe(0);
    });
});

test('subject admission does not fire retrieved observers before foreign ownership is denied', function (): void {
    adoptActivityHost();
    $runner = app(TenantRunner::class);
    $foreign = $runner->run(new TenantId(TenantScenario::B), fn () => TenantActivitySubject::create([
        'name' => 'B secret',
        'tenant_id' => TenantScenario::B,
    ]));
    TenantActivitySubject::$retrievedCount = 0;

    $runner->run(new TenantId(TenantScenario::A), function () use ($foreign): void {
        expect(fn () => app(ActivityRecorder::class)->record($foreign, 'updated'))
            ->toThrow(TenantBoundaryViolation::class)
            ->and(TenantActivitySubject::$retrievedCount)->toBe(0);
    });
});

test('supplied subjects cannot replace canonical storage or a persisted identity', function (): void {
    adoptActivityHost();
    $runner = app(TenantRunner::class);
    $subject = $runner->run(new TenantId(TenantScenario::A), fn () => TenantActivitySubject::create([
        'name' => 'Canonical A',
        'tenant_id' => TenantScenario::A,
    ]));
    config()->set('database.connections.activity_foreign', config('database.connections.sqlite'));

    $runner->run(new TenantId(TenantScenario::A), function () use ($subject): void {
        $forgedTable = clone $subject;
        $forgedTable->setTable('forged_activity_subjects');
        $forgedConnection = clone $subject;
        $forgedConnection->setConnection('activity_foreign');
        $dirtyIdentity = clone $subject;
        $dirtyIdentity->setAttribute($dirtyIdentity->getKeyName(), 999999);

        expect(fn () => app(ActivityRecorder::class)->record($forgedTable, 'updated'))
            ->toThrow(TenantBoundaryViolation::class, 'canonical registered storage')
            ->and(fn () => app(ActivityRecorder::class)->record($forgedConnection, 'updated'))
            ->toThrow(TenantBoundaryViolation::class, 'canonical registered storage')
            ->and(fn () => app(ActivityRecorder::class)->record($dirtyIdentity, 'updated'))
            ->toThrow(TenantBoundaryViolation::class, 'canonical persisted identities');
    });
});

test('adopted Activity storage denies recorder queries and hydration when tenancy is disabled', function (): void {
    $runner = app(TenantRunner::class);
    $activity = $runner->run(
        new TenantId(TenantScenario::A),
        fn () => app(ActivityRecorder::class)->record(null, 'adopted_fact'),
    );
    config()->set('tenancy.enabled', false);

    expect(fn () => app(ActivityRecorder::class)->record(null, 'legacy_write'))
        ->toThrow(TenantSchemaNotReady::class)
        ->and(fn () => ActivityLog::query()->count())
        ->toThrow(TenantSchemaNotReady::class)
        ->and(fn () => app(ActivityRelationLoader::class)->load(new Collection([$activity])))
        ->toThrow(TenantSchemaNotReady::class);
});

test('tenant retention dispatch remains denied until its bounded W6 dispatcher exists', function (): void {
    $runner = app(TenantRunner::class);
    $activity = $runner->run(
        new TenantId(TenantScenario::A),
        fn () => app(ActivityRecorder::class)->record(null, 'retained_fact'),
    );

    $runner->run(new TenantId(TenantScenario::A), function () use ($activity): void {
        expect(fn () => app(QueueActivityLogPurgeAction::class)->execute(90))
            ->toThrow(TenantBoundaryViolation::class)
            ->and(app(ActivityReadService::class)->latest()->modelKeys())
            ->toContain($activity->getKey());
    });
});

test('automatic deletion retains immutable ownership after its subject is removed', function (): void {
    adoptActivityHost();
    $runner = app(TenantRunner::class);
    $subject = $runner->run(new TenantId(TenantScenario::A), fn () => TenantActivitySubject::create([
        'name' => 'Delete me',
        'tenant_id' => TenantScenario::A,
    ]));

    $runner->run(new TenantId(TenantScenario::A), function () use ($subject): void {
        $subject->delete();
        $rows = app(ActivityReadService::class)->forSubjectKey($subject->getMorphClass(), $subject->getKey());

        expect($rows->pluck('event')->all())->toBe(['deleted', 'created'])
            ->and($rows->pluck('ownership_key')->unique()->all())->toBe(['tenant:'.TenantScenario::A]);
    });
});

test('a configured global causer uses a fixed safe projection in each tenant partition', function (): void {
    Schema::create('global_activity_causers', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->string('password');
        $table->string('account_audit');
        $table->timestamps();
    });
    config()->set([
        'activity.causer_suggestions.model' => GlobalActivityCauser::class,
        'activity.causer_suggestions.label_attribute' => 'name',
        'activity.causer_suggestions.sublabel_attribute' => 'email',
        'activity.causer_suggestions.search_attributes' => ['password', 'name'],
    ]);
    $causer = GlobalActivityCauser::create([
        'name' => 'Shared Operator',
        'email' => 'shared@example.test',
        'password' => 'credential-secret',
        'account_audit' => 'private-audit-fact',
    ]);
    $runner = app(TenantRunner::class);
    $a = $runner->run(new TenantId(TenantScenario::A), fn () => app(ActivityRecorder::class)->record(null, 'shared_causer', actor: $causer));
    $b = $runner->run(new TenantId(TenantScenario::B), fn () => app(ActivityRecorder::class)->record(null, 'shared_causer', actor: $causer));

    $runner->run(new TenantId(TenantScenario::A), function () use ($a, $b): void {
        $rows = app(ActivityReadService::class)->latest();
        app(ActivityRelationLoader::class)->load($rows);
        $lazy = ActivityLog::query()->findOrFail($a->getKey());
        $explicit = ActivityLog::query()->findOrFail($a->getKey());
        $eager = ActivityLog::query()->with('causer')->findOrFail($a->getKey());
        $preloaded = ActivityLog::query()->findOrFail($a->getKey());
        $preloaded->setRelation('causer', GlobalActivityCauser::query()->findOrFail($a->causer_id));
        $suggestions = app(ListActivityCauserSuggestionsAction::class)->execute('Shared');
        $credentialSearch = app(ListActivityCauserSuggestionsAction::class)->execute('credential-secret');

        expect($rows->modelKeys())->toBe([$a->getKey()])->not->toContain($b->getKey())
            ->and(array_keys($rows->first()->causer->getAttributes()))->toBe(['id', 'name', 'email'])
            ->and(array_keys($lazy->causer->getAttributes()))->toBe(['id', 'name', 'email'])
            ->and(array_keys($explicit->causer()->firstOrFail()->getAttributes()))->toBe(['id', 'name', 'email'])
            ->and(array_keys($eager->causer->getAttributes()))->toBe(['id', 'name', 'email'])
            ->and(array_keys($preloaded->causer->getAttributes()))->toBe(['id', 'name', 'email'])
            ->and($suggestions)->toHaveCount(1)
            ->and($suggestions->first()->label)->toBe('Shared Operator')
            ->and($credentialSearch)->toHaveCount(0);
    });
});

test('registered causer association rejects a changed identity without recording a fact', function (): void {
    adoptActivityHost();
    config()->set('activity.causer_suggestions.model', TenantActivitySubject::class);
    $runner = app(TenantRunner::class);
    $a = $runner->run(new TenantId(TenantScenario::A), fn () => TenantActivitySubject::create([
        'name' => 'A causer',
        'tenant_id' => TenantScenario::A,
    ]));
    $b = $runner->run(new TenantId(TenantScenario::B), fn () => TenantActivitySubject::create([
        'name' => 'B causer',
        'tenant_id' => TenantScenario::B,
    ]));

    $runner->run(new TenantId(TenantScenario::A), function () use ($a, $b): void {
        $before = ActivityLog::query()->count();
        $a->setAttribute($a->getKeyName(), $b->getKey());

        expect(fn () => app(ActivityRecorder::class)->record(null, 'dirty_registered_causer', actor: $a))
            ->toThrow(TenantBoundaryViolation::class)
            ->and(ActivityLog::query()->count())->toBe($before);
    });
});

test('global causer association requires one canonical persisted identity', function (): void {
    Schema::create('global_activity_causers', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->string('password');
        $table->string('account_audit');
        $table->timestamps();
    });
    config()->set('activity.causer_suggestions.model', GlobalActivityCauser::class);
    config()->set('database.connections.activity_foreign', config('database.connections.sqlite'));
    $canonical = GlobalActivityCauser::create([
        'name' => 'Canonical Operator',
        'email' => 'canonical@example.test',
        'password' => 'private-password',
        'account_audit' => 'private-audit',
    ]);
    $transient = new GlobalActivityCauser([
        'name' => 'Transient',
        'email' => 'transient@example.test',
    ]);
    $nonexistent = new GlobalActivityCauser;
    $nonexistent->setRawAttributes(['id' => 999999], true);
    $nonexistent->exists = true;
    $forgedTable = clone $canonical;
    $forgedTable->setTable('forged_global_causers');
    $forgedConnection = clone $canonical;
    $forgedConnection->setConnection('activity_foreign');
    $dirtyIdentity = clone $canonical;
    $dirtyIdentity->setAttribute($dirtyIdentity->getKeyName(), 999999);
    $runner = app(TenantRunner::class);

    $runner->run(new TenantId(TenantScenario::A), function () use (
        $canonical,
        $transient,
        $nonexistent,
        $forgedTable,
        $forgedConnection,
        $dirtyIdentity,
    ): void {
        $before = ActivityLog::query()->count();

        foreach ([$transient, $nonexistent, $forgedTable, $forgedConnection, $dirtyIdentity] as $invalid) {
            expect(fn () => app(ActivityRecorder::class)->record(null, 'invalid_global_causer', actor: $invalid))
                ->toThrow(TenantBoundaryViolation::class);
        }

        $activity = app(ActivityRecorder::class)->record(null, 'valid_global_causer', actor: $canonical);

        expect(ActivityLog::query()->count())->toBe($before + 1)
            ->and($activity->causer_id)->toEqual($canonical->getKey())
            ->and(array_keys($activity->causer->getAttributes()))->toBe(['id', 'name', 'email']);
    });
});

test('configured identity presentation cannot reclassify a registered tenant causer as global', function (): void {
    adoptActivityHost();
    config()->set('activity.causer_suggestions.model', TenantActivitySubject::class);
    $runner = app(TenantRunner::class);
    $foreign = $runner->run(new TenantId(TenantScenario::B), fn () => TenantActivitySubject::create([
        'name' => 'B identity',
        'tenant_id' => TenantScenario::B,
    ]));

    $runner->run(new TenantId(TenantScenario::A), function () use ($foreign): void {
        expect(fn () => app(ActivityRecorder::class)->record(null, 'forged_causer', actor: $foreign))
            ->toThrow(TenantBoundaryViolation::class);
    });
});

test('timeline host resolution applies canonical tenant ownership before hydration', function (): void {
    adoptActivityHost();
    config()->set('activity.routes.timeline_subjects', [TenantActivitySubject::class]);
    $runner = app(TenantRunner::class);
    $a = $runner->run(new TenantId(TenantScenario::A), fn () => TenantActivitySubject::create([
        'name' => 'A timeline',
        'tenant_id' => TenantScenario::A,
    ]));
    $b = $runner->run(new TenantId(TenantScenario::B), fn () => TenantActivitySubject::create([
        'name' => 'B timeline',
        'tenant_id' => TenantScenario::B,
    ]));

    $runner->run(new TenantId(TenantScenario::A), function () use ($a, $b): void {
        $resolver = app(ActivitySubjectTimelineResolver::class);
        expect($resolver->resolve($a->getMorphClass(), (string) $a->getKey())->getKey())->toBe($a->getKey())
            ->and(fn () => $resolver->resolve($b->getMorphClass(), (string) $b->getKey()))
            ->toThrow(ActivityTimelineException::class);
    });
});

test('a deferred value-free setting event records in its serialized producer context', function (): void {
    Event::listen(
        ActivitySettingChanged::class,
        RecordActivitySettingChanged::class,
    );
    $runner = app(TenantRunner::class);
    $event = $runner->run(
        new TenantId(TenantScenario::A),
        fn () => new ActivitySettingChanged(
            id: 'setting-123',
            key: 'core.currency.default',
            revision: 2,
            operation: 'set',
        ),
    );

    $runner->run(
        new TenantId(TenantScenario::B),
        fn () => Event::dispatch($event),
    );

    $aRows = $runner->run(
        new TenantId(TenantScenario::A),
        fn () => app(ActivityReadService::class)->forSubjectKey('nvl_setting', 'setting-123'),
    );
    $bRows = $runner->run(
        new TenantId(TenantScenario::B),
        fn () => app(ActivityReadService::class)->forSubjectKey('nvl_setting', 'setting-123'),
    );

    expect($aRows)->toHaveCount(1)
        ->and($aRows->first()->properties->get('context'))->toBe([
            'key' => 'core.currency.default',
            'revision' => 2,
        ])
        ->and($bRows)->toHaveCount(0)
        ->and(property_exists($event, 'value'))->toBeFalse();
});

test('platform setting facts cannot capture a deferred queue envelope', function (): void {
    $runner = app(TenantRunner::class);

    expect(fn () => $runner->platform(
        new PlatformOperation('activity-platform-setting', 'test', 'pest'),
        fn () => new ActivitySettingChanged('setting-123', 'core.currency.default', 2, 'set'),
    ))->toThrow(TenantBoundaryViolation::class, 'recorded synchronously');
});

test('activity ownership remains immutable after persistence', function (): void {
    $runner = app(TenantRunner::class);
    $activity = $runner->run(
        new TenantId(TenantScenario::A),
        fn () => app(ActivityRecorder::class)->record(null, 'immutable_fact'),
    );

    $runner->run(new TenantId(TenantScenario::A), function () use ($activity): void {
        $activity->ownership_key = 'tenant:'.TenantScenario::B;
        expect(fn () => $activity->save())->toThrow(TenantBoundaryViolation::class, 'immutable');
    });
});

test('unregistered foreign subject types are never constructed or queried during hydration', function (): void {
    $runner = app(TenantRunner::class);
    $activity = $runner->run(
        new TenantId(TenantScenario::A),
        fn () => app(ActivityRecorder::class)->recordForSubjectReference(
            new ActivitySubjectReference(UnknownActivitySubject::class, 'foreign-id'),
            'external_event',
        ),
    );
    UnknownActivitySubject::$constructed = 0;

    $runner->run(new TenantId(TenantScenario::A), function () use ($activity): void {
        $rows = new Collection([$activity]);
        app(ActivityRelationLoader::class)->load($rows);

        expect($rows->first()->subject)->toBeNull()
            ->and(UnknownActivitySubject::$constructed)->toBe(0);
    });
});

test('the retained model timeline bridge resolves fresh scoped services after a lifecycle reset', function (): void {
    adoptActivityHost();
    $runner = app(TenantRunner::class);
    $a = $runner->run(new TenantId(TenantScenario::A), fn () => TenantActivitySubject::create([
        'name' => 'A scope',
        'tenant_id' => TenantScenario::A,
    ]));
    $firstService = app(ModelActivityTimelineService::class);
    $aTimeline = $runner->run(new TenantId(TenantScenario::A), fn () => $a->mergedActivities());

    app()->forgetScopedInstances();
    $runner = app(TenantRunner::class);
    $b = $runner->run(new TenantId(TenantScenario::B), fn () => TenantActivitySubject::create([
        'name' => 'B scope',
        'tenant_id' => TenantScenario::B,
    ]));
    $secondService = app(ModelActivityTimelineService::class);
    $bTimeline = $runner->run(new TenantId(TenantScenario::B), fn () => $b->mergedActivities());

    expect($secondService)->not->toBe($firstService)
        ->and($aTimeline)->toHaveCount(1)
        ->and($bTimeline)->toHaveCount(1)
        ->and($aTimeline[0]->id)->not->toBe($bTimeline[0]->id);
});

test('canonical hydration keeps Activity and subject reloads batched for multi-row timelines', function (): void {
    adoptActivityHost();
    $runner = app(TenantRunner::class);
    $subject = $runner->run(new TenantId(TenantScenario::A), function (): TenantActivitySubject {
        $subject = TenantActivitySubject::create([
            'name' => 'First',
            'tenant_id' => TenantScenario::A,
        ]);
        foreach (range(2, 12) as $revision) {
            $subject->update(['name' => 'Revision '.$revision]);
        }

        return $subject;
    });

    $runner->run(new TenantId(TenantScenario::A), function () use ($subject): void {
        $rows = app(ActivityReadService::class)->forSubject($subject);
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ActivityRelationLoader::class)->load($rows);
        $rows->each(fn (ActivityLog $activity): mixed => $activity->subject);
        $queries = collect(DB::getQueryLog())->pluck('query');

        expect($rows)->toHaveCount(12)
            ->and($queries->filter(fn (string $query): bool => str_contains($query, 'activity_log')))->toHaveCount(1)
            ->and($queries->filter(fn (string $query): bool => str_starts_with($query, 'select * from "tenant_activity_subjects"')))->toHaveCount(1)
            ->and($queries->count())->toBeLessThan(12);
    });
});

/** Adopt the host resource through its own package-local adapter before creating tenant rows. */
function adoptActivityHost(): void
{
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('activity-fixtures.subjects', 'activity-fixtures', TenantActivitySubject::class));
    app(TenantAdoptionRegistry::class)->register('activity-fixtures', ActivityHostAdoptionAdapter::class);
    TenantScenario::activate(['activity-fixtures']);
    app(MappingRegistry::class)->register(new TestActivityMapping(TenantActivitySubject::class));
}
