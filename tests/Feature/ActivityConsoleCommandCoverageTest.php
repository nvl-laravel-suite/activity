<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\ServiceProvider;
use Nvl\Activity\Enums\ActivitySource;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Activity\Tests\Stubs\TestActivityCauser;
use Nvl\Activity\Tests\Stubs\TestActivityTimelineSubject;

test('doctor renders text and json while strict mode promotes warnings to failures', function (): void {
    config()->set('cache.default', 'file');
    config()->set('queue.default', 'sync');

    $this->artisan('nvl:activity:doctor')
        ->expectsOutputToContain('[PASS] schema.connection:')
        ->expectsOutputToContain('[WARNING] retention.queue:')
        ->assertSuccessful();

    $this->artisan('nvl:activity:doctor', ['--strict' => true, '--format' => 'json'])
        ->expectsOutputToContain('"healthy": false')
        ->assertFailed();

    config()->set('queue.default', 'database');
    config()->set(
        'queue.connections.database.retry_after',
        PurgeActivityLogsJob::TIMEOUT_SECONDS + 60,
    );

    $this->artisan('nvl:activity:doctor', ['--strict' => true, '--format' => 'json'])
        ->expectsOutputToContain('"healthy": true')
        ->assertSuccessful();
});

test('migration publishing is timestamp-aware and doctor detects duplicate ownership', function (): void {
    $migrationPath = realpath(dirname(__DIR__, 2).'/database/migrations');
    $publishableMigrationPaths = array_map(
        static fn (string $path): string|false => realpath($path),
        ServiceProvider::publishableMigrationPaths(),
    );

    expect(ActivityServiceProvider::pathsToPublish(
        ActivityServiceProvider::class,
        'activity-migrations',
    ))->not->toBeEmpty()
        ->and($publishableMigrationPaths)->toContain($migrationPath);

    $published = database_path(
        'migrations/2099_01_01_000000_create_activity_log_table.php',
    );
    file_put_contents($published, "<?php\n");

    try {
        $this->artisan('nvl:activity:doctor')
            ->expectsOutputToContain('create_activity_log_table')
            ->assertSuccessful();
        $this->artisan('nvl:activity:doctor', ['--strict' => true])
            ->expectsOutputToContain('create_activity_log_table')
            ->assertFailed();
    } finally {
        unlink($published);
    }
});

test('purge commands report invalid criteria instead of dispatching work', function (): void {
    Bus::fake();

    $this->artisan('nvl:activity:purge', ['--days' => 0])
        ->expectsOutputToContain('Days must be a positive integer.')
        ->assertFailed();

    $this->artisan('nvl:activity:purge-system', ['--before' => 'not-a-date'])
        ->expectsOutputToContain('The --before option must be a valid date/time.')
        ->assertFailed();

    Bus::assertNothingDispatched();
});

test('purge dry runs normalize every filter and leave matching activity untouched', function (): void {
    $this->travelTo('2026-04-10 12:00:00');

    $activity = ActivityLog::query()->create([
        'log_name' => 'consumer',
        'description' => 'Old mapped activity',
        'event' => 'synchronized',
        'subject_type' => TestActivityTimelineSubject::class,
        'subject_id' => 'subject-1',
        'causer_type' => TestActivityCauser::class,
        'causer_id' => 'causer-1',
        'properties' => ['source' => ActivitySource::User->value],
        'created_at' => '2025-06-01 12:00:00',
        'updated_at' => '2025-06-01 12:00:00',
    ]);

    $this->artisan('nvl:activity:purge', [
        '--before' => '2026-01-01',
        '--after' => '2025-01-01',
        '--event' => [' synchronized ', 'synchronized'],
        '--log-name' => [' consumer '],
        '--subject-type' => [TestActivityTimelineSubject::class],
        '--subject-id' => [' subject-1 '],
        '--causer-type' => [TestActivityCauser::class],
        '--causer-id' => [' causer-1 '],
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('events: synchronized')
        ->expectsOutputToContain('log names: consumer')
        ->expectsOutputToContain('subject ids: subject-1')
        ->expectsOutputToContain('causer ids: causer-1')
        ->expectsOutputToContain('Dry run: 1 activity log entries would be deleted.')
        ->assertSuccessful();

    expect($activity->fresh())->not->toBeNull();
});

test('purge commands dispatch their normalized default retention criteria', function (): void {
    Bus::fake();
    config()->set('activity.retention.default_days', 120);
    config()->set('activity.retention.system_logs_days', 'invalid');

    $this->artisan('nvl:activity:purge')
        ->expectsOutputToContain('Activity log purge job dispatched.')
        ->assertSuccessful();

    $this->artisan('nvl:activity:purge-system')
        ->expectsOutputToContain('System activity log purge job dispatched.')
        ->assertSuccessful();

    Bus::assertDispatched(PurgeActivityLogsJob::class, static fn (PurgeActivityLogsJob $job): bool => $job->days === 120
        && ! $job->systemOnly
        && $job->criteria?->days === 120);
    Bus::assertDispatched(PurgeActivityLogsJob::class, static fn (PurgeActivityLogsJob $job): bool => $job->days === 90
        && $job->systemOnly
        && $job->criteria?->systemOnly === true);
});
