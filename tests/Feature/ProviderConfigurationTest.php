<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Activity\Services\ActivityDoctor;
use Nvl\Activity\Tests\Stubs\TestActivityTimelineSubject;
use Nvl\Activity\Tests\Stubs\TestActivityUser;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

test('partial consumer config preserves nested activity defaults', function (): void {
    config()->set('activity', [
        'routes' => [
            'management_middleware' => [],
            'timeline_subjects' => [TestActivityTimelineSubject::class],
        ],
        'authorization' => [
            'abilities' => [
                'view' => 'activity.custom-view',
            ],
        ],
        'causer_suggestions' => [
            'search_attributes' => ['username'],
        ],
        'retention' => [
            'system_logs_days' => 45,
            'allowed_purge_options' => [30],
        ],
        'capture' => [
            'ignored_attributes' => ['revision'],
        ],
    ]);

    (new ActivityServiceProvider(app()))->register();

    expect(config('activity.retention.system_logs_days'))->toBe(45)
        ->and(config('activity.retention.default_days'))->toBe(365)
        ->and(config('activity.retention.allowed_purge_options'))->toBe([30])
        ->and(config('activity.routes.middleware'))->toBe(['api'])
        ->and(config('activity.routes.management_middleware'))->toBe([])
        ->and(config('activity.routes.timeline_subjects'))->toBe([TestActivityTimelineSubject::class])
        ->and(config('activity.authorization.abilities.view'))->toBe('activity.custom-view')
        ->and(config('activity.authorization.abilities.timeline'))->toBeNull()
        ->and(config('activity.causer_suggestions.label_attribute'))->toBe('name')
        ->and(config('activity.causer_suggestions.search_attributes'))->toBe(['username'])
        ->and(config('activity.capture.ignored_attributes'))->toBe(['revision']);
});

test('the provider always binds the canonical model used by every activity read and write path', function (): void {
    config()->set('activity.model', SpatieActivity::class);
    config()->set('activitylog.activity_model', SpatieActivity::class);

    (new ActivityServiceProvider(app()))->register();

    expect(config('activitylog.activity_model'))->toBe(ActivityLog::class);

    $legacyOverrideCheck = collect(app(ActivityDoctor::class)->inspect())
        ->firstWhere('key', 'binding.activity_model');

    expect($legacyOverrideCheck?->passed)->toBeFalse();

    config()->set('activity.model');
    config()->set('activitylog.activity_model', SpatieActivity::class);

    $check = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'binding.activity_model');

    expect($check?->passed)->toBeFalse();
});

test('management routes are disabled and doctor reports the clean schema', function (): void {
    config()->set('cache.default', 'file');
    config()->set('queue.default', 'database');
    config()->set(
        'queue.connections.database.retry_after',
        PurgeActivityLogsJob::TIMEOUT_SECONDS + 60,
    );

    $this->getJson('/api/v1/activities')->assertNotFound();

    expect(config('activity.routes.enabled'))->toBeFalse()
        ->and(collect(app(ActivityDoctor::class)->inspect())->every(
            static fn ($check): bool => $check->passed,
        ))->toBeTrue();
});

test('doctor rejects stringly typed switches that would be unsafe after config caching', function (): void {
    config()->set('activity.migrations.enabled', 'false');

    $check = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'configuration.values');

    expect($check?->passed)->toBeFalse();
});

test('doctor requires every configured timeline subject to implement the timeline contract', function (): void {
    config()->set('activity.routes.enabled', true);
    config()->set('activity.routes.management_middleware', ['auth']);
    config()->set('activity.authorization.abilities', [
        'view' => 'activity.view',
        'timeline' => 'activity.timeline',
        'purge' => 'activity.purge',
    ]);
    config()->set('activity.routes.timeline_subjects', [TestActivityUser::class]);

    $invalidCheck = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'routes.management');

    config()->set('activity.routes.timeline_subjects', [TestActivityTimelineSubject::class]);

    $undefinedAbilitiesCheck = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'routes.management');

    Gate::define('activity.view', static fn (TestActivityUser $user): bool => $user->getKey() === 1);
    Gate::define(
        'activity.timeline',
        static fn (TestActivityUser $user, TestActivityTimelineSubject $subject): bool => $user->getKey() === 1
            && $subject->exists,
    );

    $partialAbilitiesCheck = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'routes.management');

    Gate::define('activity.purge', static fn (TestActivityUser $user): bool => $user->getKey() === 1);

    $validCheck = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'routes.management');

    expect($invalidCheck?->passed)->toBeFalse()
        ->and($undefinedAbilitiesCheck?->passed)->toBeFalse()
        ->and($partialAbilitiesCheck?->passed)->toBeFalse()
        ->and($validCheck?->passed)->toBeTrue();
});
