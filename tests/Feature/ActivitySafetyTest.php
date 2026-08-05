<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Actions\Activity\QueueActivityLogPurgeAction;
use Nvl\Activity\Enums\ActivitySource;
use Nvl\Activity\Enums\ActivityVisibility;
use Nvl\Activity\Events\ActivityLogPurgeQueuedEvent;
use Nvl\Activity\Exceptions\ActivityConfigurationException;
use Nvl\Activity\Exceptions\ActivityPurgeCriteriaException;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Activity\Services\ActivityDoctor;
use Nvl\Activity\Services\ActivityReadService;
use Nvl\Activity\Support\ActivityPurgeCriteria;

test('the package migration never adopts ownership of an existing activity table', function (): void {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_07_25_090858_create_activity_log_table.php';

    Schema::drop('activity_log');
    Schema::create('activity_log', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('legacy_marker');
    });

    try {
        expect(fn () => DB::transaction(static fn () => $migration->up()))
            ->toThrow('PDOException')
            ->and(Schema::hasColumn('activity_log', 'legacy_marker'))->toBeTrue();
    } finally {
        Schema::drop('activity_log');
        $migration->up();
    }
});

test('package managed migrations reject mutable custom storage targets before ddl', function (): void {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_07_25_090858_create_activity_log_table.php';
    config()->set('activity.storage.table', 'custom_activity_log');

    try {
        expect(fn () => $migration->up())->toThrow(
            LogicException::class,
            'use an application-owned migration for custom storage',
        )->and(Schema::hasTable('custom_activity_log'))->toBeFalse()
            ->and(collect(app(ActivityDoctor::class)->inspect())
                ->firstWhere('key', 'configuration.values')?->passed)->toBeFalse();
    } finally {
        config()->set('activity.storage.table', 'activity_log');
    }
});

test('package managed migrations reject malformed storage settings before ddl', function (string $key, mixed $value): void {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_07_25_090858_create_activity_log_table.php';
    $originalValue = config($key);

    Schema::drop('activity_log');
    config()->set($key, $value);

    try {
        expect(fn () => $migration->up())->toThrow(
            LogicException::class,
            'use an application-owned migration for custom storage',
        )->and(Schema::hasTable('activity_log'))->toBeFalse();
    } finally {
        config()->set($key, $originalValue);
        $migration->up();
    }
})->with([
    'blank connection' => ['activity.storage.connection', ' '],
    'non-string connection' => ['activity.storage.connection', 123],
    'blank table' => ['activity.storage.table', ' '],
    'null table' => ['activity.storage.table', null],
    'non-string table' => ['activity.storage.table', 123],
]);

test('the activity model rejects malformed runtime storage instead of falling back silently', function (): void {
    config()->set('activity.storage.table', null);

    expect(fn () => (new ActivityLog)->getTable())->toThrow(
        ActivityConfigurationException::class,
        'Activity table name cannot be empty.',
    );

    config()->set('activity.storage.table', ActivityLog::DEFAULT_TABLE);
    config()->set('activity.storage.connection', ' ');

    expect(fn () => (new ActivityLog)->getConnectionName())->toThrow(
        ActivityConfigurationException::class,
        'Activity storage connection must be null or a non-empty connection name.',
    );
});

test('rollback always reverses the immutable managed target after storage config changes', function (): void {
    $migrationPath = dirname(__DIR__, 2).'/database/migrations/2026_07_25_090858_create_activity_log_table.php';
    $migration = require $migrationPath;

    Schema::drop('activity_log');
    Schema::create('custom_activity_log', function (Blueprint $table): void {
        $table->id();
        $table->string('decoy');
    });

    try {
        $migration->up();
        config()->set('activity.storage.table', 'custom_activity_log');

        $rollbackMigration = require $migrationPath;
        $rollbackMigration->down();

        expect(Schema::hasTable('activity_log'))->toBeFalse()
            ->and(Schema::hasColumn('custom_activity_log', 'decoy'))->toBeTrue();
    } finally {
        config()->set('activity.storage.table', 'activity_log');
        Schema::dropIfExists('custom_activity_log');

        if (! Schema::hasTable('activity_log')) {
            $migration->up();
        }
    }
});

test('system retention scheduling is disabled by default', function (): void {
    $commands = collect(app(Schedule::class)->events())
        ->map(static fn ($event): string => (string) $event->command);

    expect($commands->contains(
        static fn (string $command): bool => str_contains($command, 'nvl:activity:purge-system'),
    ))->toBeFalse();
});

test('system retention scheduling requires explicit enablement', function (): void {
    config()->set('activity.retention.schedule.enabled', true);
    config()->set('activity.retention.schedule.time', '03:15');
    config()->set('activity.retention.system_logs_days', 45);

    $provider = new ActivityServiceProvider(app());
    $method = new ReflectionMethod($provider, 'registerCommandSchedules');
    $method->invoke($provider);

    $event = collect(app(Schedule::class)->events())
        ->first(static fn ($event): bool => str_contains(
            (string) $event->command,
            'nvl:activity:purge-system',
        ));

    expect($event)->not->toBeNull()
        ->and($event->command)->toContain('--days=45')
        ->and($event->expression)->toBe('15 3 * * *');
});

test('system-only retention preserves user-originated audit rows', function (): void {
    $createdAt = now()->subDays(120);

    ActivityLog::query()->create([
        'log_name' => 'test',
        'description' => 'User audit event',
        'event' => 'reviewed',
        'properties' => [
            'source' => ActivitySource::User->value,
            'visibility' => ActivityVisibility::AuditOnly->value,
        ],
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    ActivityLog::query()->create([
        'log_name' => 'test',
        'description' => 'Scalar actor audit event',
        'event' => 'reviewed',
        'properties' => [
            'actor_id' => 'operator-1',
            'source' => ActivitySource::User->value,
            'visibility' => ActivityVisibility::AuditOnly->value,
        ],
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    ActivityLog::query()->create([
        'log_name' => 'test',
        'description' => 'System event',
        'event' => 'synchronized',
        'properties' => [
            'source' => ActivitySource::System->value,
            'visibility' => ActivityVisibility::Timeline->value,
        ],
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    expect(PurgeActivityLogsJob::countPurgeableForCriteria(
        ActivityPurgeCriteria::fromDays(90, systemOnly: true),
    ))->toBe(1);
});

test('configured retention becomes the command default only when no cutoff is supplied', function (): void {
    expect(ActivityPurgeCriteria::fromConsoleOptions([], defaultDays: 365)->days)
        ->toBe(365)
        ->and(ActivityPurgeCriteria::fromConsoleOptions(
            ['before' => '2026-01-01'],
            defaultDays: 365,
        )->days)->toBeNull();
});

test('purge criteria reject fractional days and inverted effective ranges', function (): void {
    expect(fn () => ActivityPurgeCriteria::fromConsoleOptions(['days' => '1.5']))
        ->toThrow(ActivityPurgeCriteriaException::class, 'Days must be a positive integer.')
        ->and(fn () => ActivityPurgeCriteria::fromConsoleOptions([
            'days' => '90',
            'after' => now()->subDays(30)->toIso8601String(),
        ]))
        ->toThrow(ActivityPurgeCriteriaException::class, '--after must be earlier than the effective purge cutoff.');
});

test('purge queue actions reject invalid retention before dispatch', function (): void {
    Bus::fake();

    expect(fn () => (new QueueActivityLogPurgeAction)->execute(0))
        ->toThrow(ActivityPurgeCriteriaException::class, 'Days must be a positive integer.');

    Bus::assertNothingDispatched();
});

test('doctor rejects incompatible adopted schemas instead of reporting a false healthy state', function (): void {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_07_25_090858_create_activity_log_table.php';

    Schema::drop('activity_log');
    Schema::create('activity_log', function (Blueprint $table): void {
        $table->id();
        $table->string('log_name')->nullable();
        $table->text('description');
        $table->string('subject_type')->nullable();
        $table->unsignedBigInteger('subject_id')->nullable();
        $table->string('event')->nullable();
        $table->string('causer_type')->nullable();
        $table->unsignedBigInteger('causer_id')->nullable();
        $table->unsignedInteger('attribute_changes')->nullable();
        $table->unsignedInteger('properties')->nullable();
        $table->uuid('batch_uuid')->nullable();
        $table->timestamps();
    });

    try {
        $checks = collect(app(ActivityDoctor::class)->inspect())->keyBy('key');

        expect($checks->get('schema.identifiers')?->passed)->toBeFalse()
            ->and($checks->get('schema.json')?->passed)->toBeFalse()
            ->and($checks->get('schema.indexes')?->passed)->toBeFalse();
    } finally {
        Schema::drop('activity_log');
        $migration->up();
    }
});

test('doctor requires a primary activity id and compatible batch identifier storage', function (): void {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_07_25_090858_create_activity_log_table.php';

    Schema::drop('activity_log');
    Schema::create('activity_log', function (Blueprint $table): void {
        $table->string('id');
        $table->string('log_name')->nullable()->index();
        $table->text('description');
        $table->string('subject_type')->nullable();
        $table->string('subject_id')->nullable();
        $table->string('event')->nullable();
        $table->string('causer_type')->nullable();
        $table->string('causer_id')->nullable();
        $table->json('attribute_changes')->nullable();
        $table->json('properties')->nullable();
        $table->unsignedInteger('batch_uuid')->nullable();
        $table->timestamps();

        $table->index(['subject_type', 'subject_id']);
        $table->index(['causer_type', 'causer_id']);
        $table->index(['created_at', 'id']);
        $table->index(['event', 'created_at']);
    });

    try {
        $check = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'schema.identifiers');

        expect($check?->passed)->toBeFalse();
    } finally {
        Schema::drop('activity_log');
        $migration->up();
    }
});

test('doctor reports an unavailable configured connection without crashing', function (): void {
    config()->set('activity.storage.connection', 'missing_activity_connection');

    try {
        $checks = collect(app(ActivityDoctor::class)->inspect())->keyBy('key');

        expect($checks->get('schema.connection')?->passed)->toBeFalse()
            ->and($checks->get('dependency.spatie_activitylog')?->passed)->toBeTrue();
    } finally {
        config()->set('activity.storage.connection');
    }
});

test('doctor rejects text json storage on PostgreSQL connections', function (): void {
    $connectionName = 'activity_doctor_postgresql';
    $pdo = new PDO('sqlite::memory:');

    DB::extend('activity-doctor-postgresql', static fn (array $config): SQLiteConnection => new class($pdo, ':memory:', '', $config) extends SQLiteConnection
    {
        public function getDriverName(): string
        {
            return 'pgsql';
        }
    });
    config()->set("database.connections.{$connectionName}", [
        'driver' => 'activity-doctor-postgresql',
        'database' => ':memory:',
    ]);
    config()->set('activity.storage.connection', $connectionName);

    try {
        Schema::connection($connectionName)->create('activity_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('event')->nullable();
            $table->string('causer_type')->nullable();
            $table->string('causer_id')->nullable();
            $table->text('properties')->nullable();
            $table->text('attribute_changes')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });

        $check = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'schema.json');

        expect($check?->passed)->toBeFalse();
    } finally {
        DB::purge($connectionName);
        config()->set('activity.storage.connection');
    }
});

test('zero and negative public read limits never become unbounded queries', function (): void {
    ActivityLog::query()->create([
        'log_name' => 'test',
        'description' => 'Bounded activity',
        'event' => 'created',
    ]);

    $reader = app(ActivityReadService::class);

    expect($reader->latest(0))->toHaveCount(0)
        ->and($reader->latest(-1))->toHaveCount(0);
});

test('system purge deletes only eligible system-originated rows', function (): void {
    $createdAt = now()->subDays(120);
    $system = ActivityLog::query()->create([
        'log_name' => 'test',
        'description' => 'System activity',
        'event' => 'synchronized',
        'properties' => ['source' => ActivitySource::System->value],
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
    $user = ActivityLog::query()->create([
        'log_name' => 'test',
        'description' => 'User activity',
        'event' => 'reviewed',
        'properties' => [
            'source' => ActivitySource::User->value,
            'visibility' => ActivityVisibility::AuditOnly->value,
        ],
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    (new PurgeActivityLogsJob(days: 90, systemOnly: true))->handle();

    expect($system->fresh())->toBeNull()
        ->and($user->fresh())->not->toBeNull();
});

test('purge lock contention remains retryable beyond the exception limit', function (): void {
    $createdAt = now()->subDays(120);
    $activity = ActivityLog::query()->create([
        'log_name' => 'test',
        'description' => 'Locked activity',
        'event' => 'synchronized',
        'properties' => ['source' => ActivitySource::System->value],
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
    $lock = Cache::lock('nvl:activity:purge', 60);

    expect($lock->get())->toBeTrue();

    try {
        $fakeQueueJob = new FakeJob;
        $fakeQueueJob->attempts = 60;
        $job = (new PurgeActivityLogsJob(days: 90, systemOnly: true))->setJob($fakeQueueJob);
        $job->handle();

        expect($activity->fresh())->not->toBeNull()
            ->and($job->attempts())->toBe(60)
            ->and($job->retryUntil()->getTimestamp())->toBe(now()->addSeconds(
                (int) config('activity.retention.lock_seconds')
                + (PurgeActivityLogsJob::TIMEOUT_SECONDS * $job->maxExceptions)
                + (max($job->backoff()) * ($job->maxExceptions - 1))
                + 60,
            )->getTimestamp());
        $job->assertReleased(delay: 60);
    } finally {
        $lock->release();
    }
});

test('purge jobs are queued on the configured channel after commit', function (): void {
    config()->set('activity.retention.queue', 'activity-maintenance');

    $job = new PurgeActivityLogsJob(days: 90);

    expect($job->queue)->toBe('activity-maintenance')
        ->and($job->afterCommit)->toBeTrue()
        ->and(property_exists($job, 'tries'))->toBeFalse()
        ->and($job->maxExceptions)->toBe(5)
        ->and(PurgeActivityLogsJob::TIMEOUT_SECONDS)->toBe(900)
        ->and($job->timeout)->toBe(PurgeActivityLogsJob::TIMEOUT_SECONDS)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->backoff())->toBe([60, 300, 900, 1800])
        ->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(
            now()->addSeconds((int) config('activity.retention.lock_seconds'))->getTimestamp(),
        );
});

test('strict doctor readiness rejects sync queues even when routes and scheduling are disabled', function (): void {
    config()->set('activity.routes.enabled', false);
    config()->set('activity.retention.schedule.enabled', false);
    config()->set('queue.default', 'sync');

    $check = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'retention.queue');

    expect($check?->passed)->toBeFalse();
});

test('doctor rejects process-local retention locks and validates lock providers', function (): void {
    config()->set('cache.default', 'array');

    $processLocal = collect(app(ActivityDoctor::class)->inspect())
        ->firstWhere('key', 'retention.cache_lock');

    config()->set('cache.default', 'file');
    $fileLock = collect(app(ActivityDoctor::class)->inspect())
        ->firstWhere('key', 'retention.cache_lock');

    config()->set('cache.stores.unsafe_failover', [
        'driver' => 'failover',
        'stores' => ['file', 'database'],
    ]);
    config()->set('cache.default', 'unsafe_failover');
    $unsafeFailover = collect(app(ActivityDoctor::class)->inspect())
        ->firstWhere('key', 'retention.cache_lock');

    expect($processLocal?->passed)->toBeFalse()
        ->and($fileLock?->passed)->toBeTrue()
        ->and($unsafeFailover?->passed)->toBeFalse();
});

test('doctor requires queue redelivery visibility to exceed the purge timeout', function (): void {
    config()->set('activity.retention.queue', 'activity-maintenance');
    config()->set('queue.connections', [
        'database' => [
            'driver' => 'database',
            'retry_after' => PurgeActivityLogsJob::TIMEOUT_SECONDS,
        ],
        'sqs' => [
            'driver' => 'sqs',
        ],
        'failover' => [
            'driver' => 'failover',
            'connections' => ['database', 'sqs'],
        ],
        'cycle' => [
            'driver' => 'failover',
            'connections' => ['cycle'],
        ],
        'deferred' => [
            'driver' => 'deferred',
        ],
    ]);
    config()->set('queue.default', 'database');

    $tooShort = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'retention.queue');

    config()->set(
        'queue.connections.database.retry_after',
        PurgeActivityLogsJob::TIMEOUT_SECONDS + 1,
    );
    $databaseSafe = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'retention.queue');

    config()->set('queue.default', 'sqs');
    $externalMissing = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'retention.queue');

    config()->set(
        'activity.retention.external_visibility_timeout_seconds',
        PurgeActivityLogsJob::TIMEOUT_SECONDS + 1,
    );
    $externalSafe = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'retention.queue');

    config()->set('queue.default', 'failover');
    $failoverSafe = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'retention.queue');

    config()->set('queue.default', 'cycle');
    $cycleUnsafe = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'retention.queue');

    config()->set('queue.default', 'deferred');
    $nonDurable = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'retention.queue');

    config()->set('activity.retention.external_visibility_timeout_seconds', '901');
    $invalidConfiguration = collect(app(ActivityDoctor::class)->inspect())
        ->firstWhere('key', 'configuration.values');

    expect($tooShort?->passed)->toBeFalse()
        ->and($databaseSafe?->passed)->toBeTrue()
        ->and($externalMissing?->passed)->toBeFalse()
        ->and($externalSafe?->passed)->toBeTrue()
        ->and($failoverSafe?->passed)->toBeTrue()
        ->and($cycleUnsafe?->passed)->toBeFalse()
        ->and($nonDurable?->passed)->toBeFalse()
        ->and($invalidConfiguration?->passed)->toBeFalse();
});

test('purge work and its queued event execute only after the surrounding transaction commits', function (): void {
    Event::fake([ActivityLogPurgeQueuedEvent::class]);
    $createdAt = now()->subDays(120);
    $activity = ActivityLog::query()->create([
        'log_name' => 'test',
        'description' => 'Committed purge activity',
        'event' => 'synchronized',
        'properties' => ['source' => ActivitySource::System->value],
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    DB::transaction(function () use ($activity): void {
        (new QueueActivityLogPurgeAction)->execute(90);

        expect($activity->fresh())->not->toBeNull();
        Event::assertNotDispatched(ActivityLogPurgeQueuedEvent::class);
    });

    expect($activity->fresh())->toBeNull();
    Event::assertDispatched(
        ActivityLogPurgeQueuedEvent::class,
        static fn (ActivityLogPurgeQueuedEvent $event): bool => $event->days === 90
            && $event->systemOnly === false,
    );
});

test('rolled back transactions discard purge work and its queued event', function (): void {
    Event::fake([ActivityLogPurgeQueuedEvent::class]);
    $createdAt = now()->subDays(120);
    $activity = ActivityLog::query()->create([
        'log_name' => 'test',
        'description' => 'Rolled back purge activity',
        'event' => 'synchronized',
        'properties' => ['source' => ActivitySource::System->value],
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    expect(fn () => DB::transaction(function () use ($activity): never {
        (new QueueActivityLogPurgeAction)->execute(90);

        expect($activity->fresh())->not->toBeNull();
        Event::assertNotDispatched(ActivityLogPurgeQueuedEvent::class);

        throw new RuntimeException('Rollback the purge request.');
    }))->toThrow(RuntimeException::class, 'Rollback the purge request.');

    expect($activity->fresh())->not->toBeNull();
    Event::assertNotDispatched(ActivityLogPurgeQueuedEvent::class);
});

test('purge dry runs report eligibility without deleting rows', function (): void {
    $createdAt = now()->subDays(120);
    $activity = ActivityLog::query()->create([
        'log_name' => 'test',
        'description' => 'Dry run activity',
        'event' => 'synchronized',
        'properties' => ['source' => ActivitySource::System->value],
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    $this->artisan('nvl:activity:purge-system', ['--days' => 90, '--dry-run' => true])
        ->expectsOutputToContain('Dry run: 1 system log entries would be deleted.')
        ->assertSuccessful();

    expect($activity->fresh())->not->toBeNull();
});
