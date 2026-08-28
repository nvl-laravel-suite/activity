<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Events\ActivityLogPurgeQueuedEvent;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Providers\RouteServiceProvider;
use Nvl\Activity\Tests\Stubs\TestActivityShortCircuitMiddleware;
use Nvl\Activity\Tests\Stubs\TestActivityTimelineSubject;
use Nvl\Activity\Tests\Stubs\TestActivityUser;

function enable_activity_test_routes(): void
{
    config()->set('activity.routes.enabled', true);
    config()->set('activity.routes.middleware', []);
    config()->set('activity.routes.management_middleware', []);

    (new RouteServiceProvider(app()))->map();
}

function enable_activity_default_middleware_test_routes(): void
{
    config()->set('activity.routes.enabled', true);

    (new RouteServiceProvider(app()))->map();
}

function activity_test_user(): TestActivityUser
{
    $user = new TestActivityUser;
    $user->forceFill(['id' => 1]);

    return $user;
}

test('activity index aliases are normalized by the request contract', function (): void {
    config()->set('activity.authorization.abilities.view', ' activity.view ');
    Gate::define('activity.view', static fn (TestActivityUser $user): bool => $user->getKey() === 1);
    enable_activity_test_routes();

    ActivityLog::query()->create([
        'log_name' => 'test',
        'description' => 'Created',
        'event' => 'created',
    ]);

    $this->actingAs(activity_test_user())
        ->getJson('/api/v1/activities?perPage=1')
        ->assertSuccessful()
        ->assertJsonPath('data.activities.meta.perPage', 1);
});

test('activity index accepts bounded multi-event and legacy event filters', function (): void {
    config()->set('activity.authorization.abilities.view', 'activity.view');
    Gate::define('activity.view', static fn (TestActivityUser $user): bool => $user->getKey() === 1);
    enable_activity_test_routes();

    foreach (['created', 'updated', 'deleted'] as $event) {
        ActivityLog::query()->create([
            'log_name' => 'event-filter',
            'description' => $event,
            'event' => $event,
        ]);
    }

    $this->actingAs(activity_test_user())
        ->getJson('/api/v1/activities?events=created,updated')
        ->assertSuccessful()
        ->assertJsonCount(2, 'data.activities.items');

    $this->actingAs(activity_test_user())
        ->getJson('/api/v1/activities?events[]=updated&events[]=deleted')
        ->assertSuccessful()
        ->assertJsonCount(2, 'data.activities.items');

    $this->actingAs(activity_test_user())
        ->getJson('/api/v1/activities?event=created')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data.activities.items');

    $events = implode(',', array_map(
        static fn (int $index): string => "event-{$index}",
        range(1, 11),
    ));

    $this->actingAs(activity_test_user())
        ->getJson('/api/v1/activities?events='.$events)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('events');
});

test('activity index authorization fails closed without a configured ability', function (): void {
    enable_activity_test_routes();

    $this->actingAs(activity_test_user())
        ->get('/api/v1/activities')
        ->assertForbidden()
        ->assertHeader('content-type', 'application/json');
});

test('default management middleware rejects anonymous api requests before dispatch', function (): void {
    Bus::fake();
    enable_activity_default_middleware_test_routes();

    $this->get('/api/v1/activities')
        ->assertUnauthorized()
        ->assertHeader('content-type', 'application/json');

    $this->post('/api/v1/activities/purge', ['days' => 90])
        ->assertUnauthorized()
        ->assertHeader('content-type', 'application/json');

    Bus::assertNothingDispatched();
});

test('consumer management middleware may short circuit with any symfony response', function (): void {
    config()->set('activity.routes.management_middleware', [TestActivityShortCircuitMiddleware::class]);
    enable_activity_default_middleware_test_routes();

    $this->get('/api/v1/activities')
        ->assertForbidden()
        ->assertSeeText('Consumer middleware denied the request.');
});

test('timeline requests require an allowed subject and authorize the resolved host', function (): void {
    Schema::create('activity_timeline_subjects', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    try {
        $subject = TestActivityTimelineSubject::query()->create(['name' => 'Timeline host']);
        config()->set('activity.routes.timeline_subjects', [TestActivityTimelineSubject::class]);
        config()->set('activity.authorization.abilities.timeline', 'activity.timeline');
        Gate::define(
            'activity.timeline',
            static fn (TestActivityUser $user, TestActivityTimelineSubject $host): bool => $user->getKey() === 1
                && $host->exists,
        );
        enable_activity_test_routes();

        ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'Host created',
            'event' => 'created',
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (string) $subject->getKey(),
            'properties' => [
                'source' => 'user',
                'visibility' => 'timeline',
            ],
        ]);

        $this->actingAs(activity_test_user())
            ->getJson('/api/v1/activities/timeline?subjectType='.urlencode(
                TestActivityTimelineSubject::class,
            ).'&subjectId='.$subject->getKey())
            ->assertSuccessful()
            ->assertJsonCount(1, 'data.activity')
            ->assertJsonPath('data.activity.0.event', 'created');
    } finally {
        Schema::dropIfExists('activity_timeline_subjects');
    }
});

test('denied and missing timeline subjects return the same non-enumerable response', function (): void {
    Schema::create('activity_timeline_subjects', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    try {
        $subject = TestActivityTimelineSubject::query()->create(['name' => 'Protected timeline host']);
        config()->set('activity.routes.timeline_subjects', [TestActivityTimelineSubject::class]);
        config()->set('activity.authorization.abilities.timeline', 'activity.timeline');
        Gate::define('activity.timeline', static fn (): bool => false);
        enable_activity_test_routes();

        $path = '/api/v1/activities/timeline?subjectType='.urlencode(
            TestActivityTimelineSubject::class,
        ).'&subjectId=';
        $expected = [
            'message' => 'The requested activity timeline subject was not found.',
            'code' => 'timeline_subject_not_found',
        ];

        $this->actingAs(activity_test_user())
            ->getJson($path.$subject->getKey())
            ->assertNotFound()
            ->assertExactJson($expected);

        $this->actingAs(activity_test_user())
            ->getJson($path.'999')
            ->assertNotFound()
            ->assertExactJson($expected);
    } finally {
        Schema::dropIfExists('activity_timeline_subjects');
    }
});

test('purge requests authorize once through the form request and dispatch the contract job', function (): void {
    Bus::fake();
    Event::fake([ActivityLogPurgeQueuedEvent::class]);
    config()->set('activity.authorization.abilities.purge', 'activity.purge');
    Gate::define('activity.purge', static fn (TestActivityUser $user): bool => $user->getKey() === 1);
    enable_activity_test_routes();

    $this->actingAs(activity_test_user())
        ->postJson('/api/v1/activities/purge', ['days' => 90])
        ->assertSuccessful()
        ->assertJsonPath('data.queued', true)
        ->assertJsonPath('data.days', 90)
        ->assertJsonPath('data.systemOnly', false)
        ->assertJsonPath('data.includeImportant', false)
        ->assertJsonPath('code', 'purge_queued')
        ->assertJsonPath('message', 'The activity log purge has been queued.');

    Bus::assertDispatched(
        PurgeActivityLogsJob::class,
        static fn (PurgeActivityLogsJob $job): bool => $job->days === 90 && $job->systemOnly === false,
    );
    Event::assertDispatched(
        ActivityLogPurgeQueuedEvent::class,
        static fn (ActivityLogPurgeQueuedEvent $event): bool => $event->days === 90
            && $event->systemOnly === false
            && $event->includeImportant === false,
    );
});

test('purge requests expose and audit explicit important-evidence inclusion', function (): void {
    Bus::fake();
    Event::fake([ActivityLogPurgeQueuedEvent::class]);
    config()->set('activity.authorization.abilities.purge', 'activity.purge');
    Gate::define('activity.purge', static fn (TestActivityUser $user): bool => $user->getKey() === 1);
    enable_activity_test_routes();

    $this->actingAs(activity_test_user())
        ->postJson('/api/v1/activities/purge', [
            'days' => 90,
            'include_important' => true,
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.includeImportant', true);

    Bus::assertDispatched(
        PurgeActivityLogsJob::class,
        static fn (PurgeActivityLogsJob $job): bool => $job->criteria?->includeImportant === true,
    );
    Event::assertDispatched(
        ActivityLogPurgeQueuedEvent::class,
        static fn (ActivityLogPurgeQueuedEvent $event): bool => $event->includeImportant,
    );
});

test('purge and causer suggestion endpoints fail closed when configured gates deny access', function (): void {
    Bus::fake();
    config()->set('activity.authorization.abilities', [
        'view' => 'activity.view',
        'timeline' => 'activity.timeline',
        'purge' => 'activity.purge',
    ]);
    Gate::define('activity.view', static fn (): bool => false);
    Gate::define('activity.timeline', static fn (): bool => false);
    Gate::define('activity.purge', static fn (): bool => false);
    enable_activity_test_routes();

    $this->actingAs(activity_test_user())
        ->getJson('/api/v1/activities/causers/suggestions?q=a')
        ->assertForbidden();

    $this->actingAs(activity_test_user())
        ->postJson('/api/v1/activities/purge', ['days' => 90])
        ->assertForbidden();

    Bus::assertNothingDispatched();
});

test('causer suggestions use an authorized form request with bounded aliases', function (): void {
    config()->set('activity.authorization.abilities.view', 'activity.view');
    Gate::define('activity.view', static fn (TestActivityUser $user): bool => $user->getKey() === 1);
    enable_activity_test_routes();

    $this->actingAs(activity_test_user())
        ->getJson('/api/v1/activities/causers/suggestions?q=a')
        ->assertSuccessful()
        ->assertExactJson(['data' => []]);

    $this->actingAs(activity_test_user())
        ->getJson('/api/v1/activities/causers/suggestions?limit=51')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('limit');
});

test('activity api validation errors use the active package locale and canonical field scope', function (): void {
    config()->set('activity.authorization.abilities.view', 'activity.view');
    Gate::define('activity.view', static fn (TestActivityUser $user): bool => $user->getKey() === 1);
    enable_activity_test_routes();
    app()->setLocale('bg');

    try {
        $this->actingAs(activity_test_user())
            ->getJson('/api/v1/activities/causers/suggestions?limit=51')
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.limit.0',
                'Полето „лимит“ не може да бъде по-голямо от 50.',
            );

        $this->actingAs(activity_test_user())
            ->getJson('/api/v1/activities/timeline?subjectType=unsupported&subjectId=1')
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.subject_type.0',
                'Избраният тип обект не поддържа обединена хронология на активността.',
            );
    } finally {
        app()->setLocale('en');
    }
});

test('package api failures negotiate json even when the client omits an accept header', function (): void {
    config()->set('activity.authorization.abilities.view', 'activity.view');
    Gate::define('activity.view', static fn (TestActivityUser $user): bool => $user->getKey() === 1);
    enable_activity_test_routes();

    $this->actingAs(activity_test_user())
        ->get('/api/v1/activities?perPage=101')
        ->assertUnprocessable()
        ->assertHeader('content-type', 'application/json')
        ->assertJsonValidationErrors('per_page');
});

test('missing timeline subjects return a safe coded and translated api error', function (): void {
    Schema::create('activity_timeline_subjects', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    try {
        config()->set('activity.routes.timeline_subjects', [TestActivityTimelineSubject::class]);
        config()->set('activity.authorization.abilities.timeline', 'activity.timeline');
        Gate::define(
            'activity.timeline',
            static fn (TestActivityUser $user, TestActivityTimelineSubject $host): bool => $user->getKey() === 1
                && $host->exists,
        );
        enable_activity_test_routes();
        app()->setLocale('bg');

        $this->actingAs(activity_test_user())
            ->getJson('/api/v1/activities/timeline?subjectType='.urlencode(
                TestActivityTimelineSubject::class,
            ).'&subjectId=999')
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Заявеният обект за хронология на активността не беше намерен.',
                'code' => 'timeline_subject_not_found',
            ]);
    } finally {
        app()->setLocale('en');
        Schema::dropIfExists('activity_timeline_subjects');
    }
});

test('malformed allowed subject identifiers return a safe not found response', function (): void {
    Schema::create('activity_timeline_subjects', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    try {
        config()->set('activity.routes.timeline_subjects', [TestActivityTimelineSubject::class]);
        config()->set('activity.authorization.abilities.timeline', 'activity.timeline');
        Gate::define('activity.timeline', static fn (): bool => true);
        enable_activity_test_routes();

        $this->actingAs(activity_test_user())
            ->getJson('/api/v1/activities/timeline?subjectType='.urlencode(
                TestActivityTimelineSubject::class,
            ).'&subjectId=not-an-integer')
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'The requested activity timeline subject was not found.',
                'code' => 'timeline_subject_not_found',
            ]);
    } finally {
        Schema::dropIfExists('activity_timeline_subjects');
    }
});

test('system purge responses separate stable codes from translated messages', function (): void {
    Bus::fake();
    config()->set('activity.authorization.abilities.purge', 'activity.purge');
    Gate::define('activity.purge', static fn (TestActivityUser $user): bool => $user->getKey() === 1);
    enable_activity_test_routes();
    app()->setLocale('bg');

    try {
        $this->actingAs(activity_test_user())
            ->postJson('/api/v1/activities/purge-system', ['days' => 90])
            ->assertSuccessful()
            ->assertJsonPath('data.systemOnly', true)
            ->assertJsonPath('code', 'purge_system_queued')
            ->assertJsonPath(
                'message',
                'Почистването на системната активност е добавено към опашката.',
            );
    } finally {
        app()->setLocale('en');
    }
});
