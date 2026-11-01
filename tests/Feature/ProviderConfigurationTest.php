<?php

declare(strict_types=1);

use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Ownership\ActivityOwnershipGuard;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Activity\Services\ActivityDoctor;
use Nvl\Activity\Services\ActivityPolicyRegistry;
use Nvl\Activity\Tests\Stubs\TestActivityTimelineSubject;

it('resolves legacy and neutral guard names to one scoped state holder', function (): void {
    expect(app(ActivityOwnershipGuard::class))
        ->toBe(app(ActivityOwnershipGuard::class));
});

test('advertised activity defaults omit retired configuration without runtime readers', function (): void {
    foreach (['name', 'causer_suggestions.type_attribute', 'retention.allowed_purge_options'] as $key) {
        expect(config()->has('nvl-activity.'.$key))->toBeFalse();
    }
});

test('partial consumer config preserves nested activity defaults', function (): void {
    config()->set('nvl-activity', [
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

    expect(config('nvl-activity.retention.system_logs_days'))->toBe(45)
        ->and(config('nvl-activity.retention.default_days'))->toBe(365)
        ->and(config('nvl-activity.retention.allowed_purge_options'))->toBe([30])
        ->and(config('nvl-activity.routes.middleware'))->toBeNull()
        ->and(config('nvl-activity.routes.management_middleware'))->toBe([])
        ->and(config('nvl-activity.routes.timeline_subjects'))->toBe([TestActivityTimelineSubject::class])
        ->and(config('nvl-activity.authorization.abilities.view'))->toBe('activity.custom-view')
        ->and(config('nvl-activity.authorization.abilities.timeline'))->toBeNull()
        ->and(config('nvl-activity.causer_suggestions.label_attribute'))->toBe('name')
        ->and(config('nvl-activity.causer_suggestions.search_attributes'))->toBe(['username'])
        ->and(config('nvl-activity.capture.ignored_attributes'))->toBe(['revision']);
});

test('the provider preserves host activity ownership and ignores retired package model keys', function (): void {
    config()->set('nvl-activity.model', ActivityLog::class);
    config()->set('activitylog.activity_model', ActivityLog::class);

    (new ActivityServiceProvider(app()))->register();

    expect(config('activitylog.activity_model'))->toBe(ActivityLog::class);

    $legacyOverrideCheck = collect(app(ActivityDoctor::class)->inspect())
        ->firstWhere('key', 'binding.activity_model');

    expect(($legacyOverrideCheck['passed'] ?? null))->toBeTrue();

    config()->set('nvl-activity.model');
    config()->set('activitylog.activity_model', ActivityLog::class);

    $check = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'binding.activity_model');

    expect(($check['passed'] ?? null))->toBeTrue();
});

test('management routes are disabled and doctor reports the clean schema', function (): void {
    config()->set('cache.default', 'file');
    config()->set('queue.default', 'database');
    config()->set(
        'queue.connections.database.retry_after',
        PurgeActivityLogsJob::TIMEOUT_SECONDS + 60,
    );

    $this->getJson('/nvl/api/v1/activity')->assertNotFound();

    $failedChecks = collect(app(ActivityDoctor::class)->inspect())
        ->reject(static fn ($check): bool => $check['passed'])
        ->map(static fn ($check): string => $check['key'])
        ->values()
        ->all();

    expect(config('nvl-activity.routes.enabled'))->toBeNull()
        ->and($failedChecks)->toBe([]);
});

test('doctor rejects stringly typed switches that would be unsafe after config caching', function (): void {
    config()->set('nvl-activity.migrations.enabled', 'false');

    $check = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'configuration.values');

    expect(($check['passed'] ?? null))->toBeFalse();
});

test('doctor readiness describes native storage without owning consumer routes', function (): void {
    config(['nvl-activity.routes.enabled' => true]);
    $checks = collect(app(ActivityDoctor::class)->inspect())->pluck('key')->all();
    expect($checks)->not->toContain('routes.management');
    $this->getJson('/nvl/api/v1/activity')->assertNotFound();
});

test('production consumer fixtures declare valid native event policies', function (string $consumer): void {
    $configuration = require dirname(__DIR__, 5).'/tools/fixtures/'.$consumer.'-production-consumer/config/nvl-activity.php';
    config()->set('nvl-activity.events', $configuration['events']);
    (new ActivityServiceProvider(app()))->register();
    expect(app(ActivityPolicyRegistry::class)->all())->not->toBeEmpty();
})->with(['activity', 'auth']);
