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
use Nvl\Activity\Definitions\Tables\ActivityTables;
use Nvl\Activity\Enums\ActivityImportance;
use Nvl\Activity\Enums\ActivitySource;
use Nvl\Activity\Enums\ActivityVisibility;
use Nvl\Activity\Events\ActivityLogPurgeQueued;
use Nvl\Activity\Exceptions\ActivityConfigurationException;
use Nvl\Activity\Exceptions\ActivityPurgeCriteriaException;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Activity\Services\ActivityDoctor;
use Nvl\Activity\Services\ActivityPurgeDispatcher;
use Nvl\Activity\Services\ActivityReader;
use Nvl\Activity\Support\ActivityPurgeCriteria;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

test('the package migration rejects an incompatible existing activity table', function (): void {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_07_25_090858_nvl_activity_create_activity_log_table.php';

    Schema::drop(ActivityTables::ActivityLog);
    Schema::create(ActivityTables::ActivityLog, function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('legacy_marker');
    });

    try {
        expect(fn () => DB::transaction(static fn () => $migration->up()))
            ->toThrow(LogicException::class, 'cannot be baselined; missing columns')
            ->and(Schema::hasColumn(ActivityTables::ActivityLog, 'legacy_marker'))->toBeTrue();
    } finally {
        Schema::drop(ActivityTables::ActivityLog);
        $migration->up();
    }
});

test('the package baselines native canonical storage without rewriting facts', function (): void {
    $baseline = require dirname(__DIR__, 2).'/database/migrations/2026_07_25_090858_nvl_activity_create_activity_log_table.php';

    Schema::drop(ActivityTables::ActivityLog);
    Schema::create(ActivityTables::ActivityLog, function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('log_name')->nullable()->index();
        $table->text('description');
        $table->string('subject_type')->nullable();
        $table->string('subject_id')->nullable();
        $table->string('event')->nullable();
        $table->string('causer_type')->nullable();
        $table->string('causer_id')->nullable();
        $table->json('properties')->nullable();
        $table->json('attribute_changes')->nullable();
        $table->uuid('batch_uuid')->nullable();
        $table->timestamps();

        $table->index(['subject_type', 'subject_id']);
        $table->index(['causer_type', 'causer_id']);
        $table->index(['created_at', 'id']);
        $table->index(['event', 'created_at']);
    });
    DB::table(ActivityTables::ActivityLog)->insert([
        'id' => '019c46ed-4f77-71a8-a741-216bad57d93c',
        'description' => 'Preserved historical evidence',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        $baseline->up();

        expect(Schema::hasColumn(ActivityTables::ActivityLog, 'attribute_changes'))->toBeTrue()
            ->and(DB::table(ActivityTables::ActivityLog)->count())->toBe(1);

        $baseline->down();

        expect(Schema::hasTable(ActivityTables::ActivityLog))->toBeTrue()
            ->and(Schema::hasColumn(ActivityTables::ActivityLog, 'attribute_changes'))->toBeTrue()
            ->and(DB::table(ActivityTables::ActivityLog)->count())->toBe(1);
    } finally {
        Schema::drop(ActivityTables::ActivityLog);
        $baseline->up();
    }
});

test('package managed migrations create explicitly configured fresh storage', function (): void {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_07_25_090858_nvl_activity_create_activity_log_table.php';
    config()->set('nvl-activity.tables.log', 'custom_activity_log');
    try {
        $migration->up();
        expect(Schema::hasTable('custom_activity_log'))->toBeTrue()
            ->and((collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'configuration.values')['passed'] ?? null))->toBeTrue();
    } finally {
        Schema::dropIfExists('custom_activity_log');
        config()->set('nvl-activity.tables.log', ActivityTables::ActivityLog);
    }
});

test('package managed migrations reject malformed storage settings before ddl', function (string $key, mixed $value): void {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_07_25_090858_nvl_activity_create_activity_log_table.php';
    $originalValue = config($key);

    Schema::drop(ActivityTables::ActivityLog);
    config()->set($key, $value);

    try {
        expect(fn () => $migration->up())->toThrow(
            InvalidArgumentException::class,
        )->and(Schema::hasTable(ActivityTables::ActivityLog))->toBeFalse();
    } finally {
        config()->set($key, $originalValue);
        $migration->up();
    }
})->with([
    'blank connection' => ['nvl-activity.connection', ' '],
    'non-string connection' => ['nvl-activity.connection', 123],
    'blank table' => ['nvl-activity.tables.log', ' '],
    'null table' => ['nvl-activity.tables.log', null],
    'non-string table' => ['nvl-activity.tables.log', 123],
]);

test('the activity model rejects malformed runtime storage instead of falling back silently', function (): void {
    $originalConfiguration = config('nvl-activity');

    try {
        config()->set('nvl-activity.tables.log', null);

        expect(fn () => (new ActivityLog)->getTable())->toThrow(
            ActivityConfigurationException::class,
            'Activity table name cannot be empty.',
        );

        config()->set('nvl-activity.tables.log', ActivityLog::DEFAULT_TABLE);
        config()->set('nvl-activity.connection', ' ');

        expect(fn () => (new ActivityLog)->getConnectionName())->toThrow(
            ActivityConfigurationException::class,
            'Activity storage connection must be null or a non-empty connection name.',
        );
    } finally {
        config()->set('nvl-activity', $originalConfiguration);
    }
});

test('rollback never destroys the immutable managed activity target after storage config changes', function (): void {
    $migrationPath = dirname(__DIR__, 2).'/database/migrations/2026_07_25_090858_nvl_activity_create_activity_log_table.php';
    $migration = require $migrationPath;

    Schema::drop(ActivityTables::ActivityLog);
    Schema::create('custom_activity_log', function (Blueprint $table): void {
        $table->id();
        $table->string('decoy');
    });

    try {
        $migration->up();
        config()->set('nvl-activity.tables.log', 'custom_activity_log');

        $rollbackMigration = require $migrationPath;
        $rollbackMigration->down();

        expect(Schema::hasTable(ActivityTables::ActivityLog))->toBeTrue()
            ->and(Schema::hasColumn('custom_activity_log', 'decoy'))->toBeTrue();
    } finally {
        config()->set('nvl-activity.tables.log', ActivityTables::ActivityLog);
        Schema::dropIfExists('custom_activity_log');

        if (! Schema::hasTable(ActivityTables::ActivityLog)) {
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
    config()->set('nvl-activity.retention.schedule.enabled', true);
    config()->set('nvl-activity.retention.schedule.time', '03:15');
    config()->set('nvl-activity.retention.system_logs_days', 45);

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
        ->and($event->expression)->toBe('15 3 * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
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
        'description' => 'Important system evidence',
        'event' => 'security_synchronized',
        'properties' => [
            'source' => ActivitySource::System->value,
            'importance' => ActivityImportance::Important->value,
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

test('purge preserves important evidence by default and deletes it only after explicit opt in', function (): void {
    $createdAt = now()->subDays(120);
    $normal = ActivityLog::query()->create([
        'log_name' => 'test',
        'description' => 'Normal maintenance evidence',
        'event' => 'synchronized',
        'properties' => ['importance' => ActivityImportance::Normal->value],
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
    $important = ActivityLog::query()->create([
        'log_name' => 'test',
        'description' => 'Protected compliance evidence',
        'event' => 'verified',
        'properties' => ['importance' => ActivityImportance::Important->value],
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    (new PurgeActivityLogsJob(days: 90))->handle(app(OwnershipBoundary::class));

    expect($normal->fresh())->toBeNull()
        ->and($important->fresh())->not->toBeNull()
        ->and(PurgeActivityLogsJob::countPurgeableForCriteria(
            ActivityPurgeCriteria::fromDays(90),
        ))->toBe(0)
        ->and(PurgeActivityLogsJob::countPurgeableForCriteria(
            ActivityPurgeCriteria::fromDays(90, includeImportant: true),
        ))->toBe(1);

    (new PurgeActivityLogsJob(
        days: 90,
        criteria: ActivityPurgeCriteria::fromDays(90, includeImportant: true),
    ))->handle(app(OwnershipBoundary::class));

    expect($important->fresh())->toBeNull();
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

    expect(fn () => app(ActivityPurgeDispatcher::class)->dispatch(ActivityPurgeCriteria::fromDays(0), SystemActor::Background))
        ->toThrow(ActivityPurgeCriteriaException::class, 'Days must be a positive integer.');

    Bus::assertNothingDispatched();
});

test('doctor rejects incompatible adopted schemas instead of reporting a false healthy state', function (): void {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_07_25_090858_nvl_activity_create_activity_log_table.php';

    Schema::drop(ActivityTables::ActivityLog);
    Schema::create(ActivityTables::ActivityLog, function (Blueprint $table): void {
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

        expect(($checks->get('schema.identifiers')['passed'] ?? null))->toBeFalse()
            ->and(($checks->get('schema.json')['passed'] ?? null))->toBeFalse()
            ->and(($checks->get('schema.indexes')['passed'] ?? null))->toBeFalse();
    } finally {
        Schema::drop(ActivityTables::ActivityLog);
        $migration->up();
    }
});

test('doctor requires a primary activity id and compatible batch identifier storage', function (): void {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_07_25_090858_nvl_activity_create_activity_log_table.php';

    Schema::drop(ActivityTables::ActivityLog);
    Schema::create(ActivityTables::ActivityLog, function (Blueprint $table): void {
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

        expect(($check['passed'] ?? null))->toBeFalse();
    } finally {
        Schema::drop(ActivityTables::ActivityLog);
        $migration->up();
    }
});

test('doctor reports an unavailable configured connection without crashing', function (): void {
    config()->set('nvl-activity.connection', 'missing_activity_connection');

    try {
        $checks = collect(app(ActivityDoctor::class)->inspect())->keyBy('key');

        expect(($checks->get('schema.connection')['passed'] ?? null))->toBeFalse();
    } finally {
        config()->set('nvl-activity.connection');
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
    config()->set('nvl-activity.connection', $connectionName);

    try {
        Schema::connection($connectionName)->create(ActivityTables::ActivityLog, function (Blueprint $table): void {
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

        expect(($check['passed'] ?? null))->toBeFalse();
    } finally {
        DB::purge($connectionName);
        config()->set('nvl-activity.connection');
    }
});

test('zero and negative public read limits never become unbounded queries', function (): void {
    foreach ([0, -1] as $limit) {
        expect(fn () => app(ActivityReader::class)->paginate(['per_page' => $limit], SystemActor::Background))->toThrow(InvalidArgumentException::class);
        expect(fn () => app(ActivityReader::class)->timeline(new ActivitySubjectReference('record', 'one'), SystemActor::Background, $limit))->toThrow(InvalidArgumentException::class);
    }
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

    (new PurgeActivityLogsJob(days: 90, systemOnly: true))->handle(app(OwnershipBoundary::class));

    expect($system->fresh())->toBeNull()
        ->and($user->fresh())->not->toBeNull();
});

test('purge preserves a foreign generic purge lock while deleting eligible activity', function (): void {
    $activity = ActivityLog::query()->create([
        'log_name' => 'test',
        'description' => 'Namespaced activity purge',
        'event' => 'synchronized',
        'properties' => ['source' => ActivitySource::System->value],
        'created_at' => now()->subDays(120),
        'updated_at' => now()->subDays(120),
    ]);
    $foreignLock = Cache::lock('purge', 60);
    expect($foreignLock->get())->toBeTrue();

    try {
        (new PurgeActivityLogsJob(days: 90, systemOnly: true))->handle(app(OwnershipBoundary::class));
        expect($activity->fresh())->toBeNull()
            ->and(Cache::lock('purge', 60)->get())->toBeFalse();
    } finally {
        $foreignLock->release();
    }
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
    $lock = Cache::lock(
        'nvl:activity:purge:'.hash('sha256', app(OwnershipBoundary::class)->key('activity.events', 'purge')),
        60,
    );

    expect($lock->get())->toBeTrue();

    try {
        $fakeQueueJob = new FakeJob;
        $fakeQueueJob->attempts = 60;
        $job = (new PurgeActivityLogsJob(days: 90, systemOnly: true))->setJob($fakeQueueJob);
        $job->handle(app(OwnershipBoundary::class));

        expect($activity->fresh())->not->toBeNull()
            ->and($job->attempts())->toBe(60)
            ->and($job->retryUntil()->getTimestamp())->toBe(now()->addSeconds(
                (int) config('nvl-activity.retention.lock_seconds')
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
    config()->set('nvl-activity.queue.name', 'activity-maintenance');

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
            now()->addSeconds((int) config('nvl-activity.retention.lock_seconds'))->getTimestamp(),
        );
});

test('strict doctor readiness rejects sync queues even when routes and scheduling are disabled', function (): void {
    config()->set('nvl-activity.routes.enabled', false);
    config()->set('nvl-activity.retention.schedule.enabled', false);
    config()->set('queue.default', 'sync');

    $check = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'retention.queue');

    expect(($check['passed'] ?? null))->toBeFalse();
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

    expect(($processLocal['passed'] ?? null))->toBeFalse()
        ->and(($fileLock['passed'] ?? null))->toBeTrue()
        ->and(($unsafeFailover['passed'] ?? null))->toBeFalse();
});

test('doctor requires queue redelivery visibility to exceed the purge timeout', function (): void {
    config()->set('nvl-activity.queue.name', 'activity-maintenance');
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

    config()->set('queue.connections.sqs.retry_after', PurgeActivityLogsJob::TIMEOUT_SECONDS + 1);
    $externalSafe = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'retention.queue');

    config()->set('queue.default', 'failover');
    $failoverSafe = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'retention.queue');

    config()->set('queue.default', 'cycle');
    $cycleUnsafe = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'retention.queue');

    config()->set('queue.default', 'deferred');
    $nonDurable = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'retention.queue');

    config()->set('nvl-activity.migrations.enabled', 'yes');
    $invalidConfiguration = collect(app(ActivityDoctor::class)->inspect())
        ->firstWhere('key', 'configuration.values');

    expect(($tooShort['passed'] ?? null))->toBeFalse()
        ->and(($databaseSafe['passed'] ?? null))->toBeTrue()
        ->and(($externalMissing['passed'] ?? null))->toBeFalse()
        ->and(($externalSafe['passed'] ?? null))->toBeTrue()
        ->and(($failoverSafe['passed'] ?? null))->toBeTrue()
        ->and(($cycleUnsafe['passed'] ?? null))->toBeFalse()
        ->and(($nonDurable['passed'] ?? null))->toBeFalse()
        ->and(($invalidConfiguration['passed'] ?? null))->toBeFalse();
});

test('purge work and its queued event execute only after the surrounding transaction commits', function (): void {
    Event::fake([ActivityLogPurgeQueued::class]);
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
        app(ActivityPurgeDispatcher::class)->dispatch(ActivityPurgeCriteria::fromDays(90), SystemActor::Background);

        expect($activity->fresh())->not->toBeNull();
        Event::assertNotDispatched(ActivityLogPurgeQueued::class);
    });

    expect($activity->fresh())->toBeNull();
    Event::assertDispatched(
        ActivityLogPurgeQueued::class,
        static fn (ActivityLogPurgeQueued $event): bool => $event->days === 90
            && $event->systemOnly === false,
    );
});

test('rolled back transactions discard purge work and its queued event', function (): void {
    Event::fake([ActivityLogPurgeQueued::class]);
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
        app(ActivityPurgeDispatcher::class)->dispatch(ActivityPurgeCriteria::fromDays(90), SystemActor::Background);

        expect($activity->fresh())->not->toBeNull();
        Event::assertNotDispatched(ActivityLogPurgeQueued::class);

        throw new RuntimeException('Rollback the purge request.');
    }))->toThrow(RuntimeException::class, 'Rollback the purge request.');

    expect($activity->fresh())->not->toBeNull();
    Event::assertNotDispatched(ActivityLogPurgeQueued::class);
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

test('unrelated open transactions that roll back do not discard committed activity purge dispatch', function (): void {
    Bus::fake([PurgeActivityLogsJob::class]);
    config(['database.connections.activity_unrelated_tx' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]]);

    $unrelated = DB::connection('activity_unrelated_tx');
    $unrelated->beginTransaction();

    try {
        app(ActivityPurgeDispatcher::class)->dispatch(ActivityPurgeCriteria::fromDays(90), SystemActor::Background);
    } finally {
        $unrelated->rollBack();
    }

    Bus::assertDispatched(PurgeActivityLogsJob::class);
});
