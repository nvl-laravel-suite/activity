<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Definitions\Tables\ActivityTables;
use Nvl\Activity\Services\ActivitySchemaAdopter;
use Nvl\Activity\Services\ActivitySchemaInspector;
use Nvl\Support\Adoption\AdoptionCoordinator;
use Nvl\Support\Adoption\AdoptionException;
use Nvl\Support\Adoption\AdoptionProgress;
use Nvl\Support\Adoption\AdoptionRegistry;
use Nvl\Support\Adoption\AdoptionStore;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Providers\SupportServiceProvider;

/**
 * Committed fixtures without a wrapping transaction: every DDL step and its checkpoint commit for real.
 */
beforeEach(function (): void {
    $migrations = dirname((string) (new ReflectionClass(SupportServiceProvider::class))->getFileName(), 3).'/database/migrations';
    $this->artisan('migrate', ['--path' => $migrations, '--realpath' => true])->assertSuccessful();
    config()->set('nvl-activity.tables.log', 'activity_log');
    Schema::dropIfExists('activity_log');
    Schema::create('activity_log', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('log_name')->nullable()->index();
        $table->text('description');
        $table->nullableUuidMorphs('subject', 'legacy_activity_subject');
        $table->nullableUuidMorphs('causer', 'legacy_activity_causer');
        $table->string('event')->nullable();
        $table->json('properties')->nullable();
        $table->json('attribute_changes')->nullable();
        $table->timestamps();
    });
    foreach (['019c46ed-4f77-71a8-a741-216bad57d93c', '019c46ed-4f77-71a8-a741-216bad57d93d', '019c46ed-4f77-71a8-a741-216bad57d93e'] as $position => $id) {
        DB::table('activity_log')->insert([
            'id' => $id, 'log_name' => 'legacy', 'description' => "Snapshot {$position}", 'event' => 'updated',
            'subject_type' => 'catalog-item', 'subject_id' => '019c46ed-4f77-71a8-a741-216bad57d93a',
            'causer_type' => null, 'causer_id' => null,
            'properties' => '{"context":{"reference":"keep"}}',
            'attribute_changes' => '{"attributes":{"name":"after"},"old":{"name":"before"}}',
            'created_at' => "2025-05-1{$position} 10:20:30", 'updated_at' => "2025-05-1{$position} 10:20:31",
        ]);
    }
});

afterEach(function (): void {
    Schema::dropIfExists('activity_log');
    config()->set('nvl-activity.tables.log', ActivityTables::ActivityLog);
});

function activitySchemaCoordinator(): AdoptionCoordinator
{
    return new AdoptionCoordinator(app(AdoptionRegistry::class)->make('activity', SystemActor::Background));
}

/** @return list<array<string, mixed>> */
function activitySchemaHistory(): array
{
    return DB::table('activity_log')->orderBy('id')->get()
        ->map(static fn (object $row): array => array_diff_key((array) $row, ['batch_uuid' => true]))->all();
}

/** @return list<string> */
function activitySchemaAdoptedIndexes(): array
{
    $names = array_column(Schema::getIndexes('activity_log'), 'name');
    $required = array_keys(app(ActivitySchemaInspector::class)->requiredIndexes(DB::connection(), 'activity_log'));
    $present = array_values(array_intersect($required, $names));
    sort($present);

    return $present;
}

/** Fail the batch that issues the given CREATE INDEX statement, after the statement itself ran. */
function activitySchemaFailOnIndex(int $statement): void
{
    $created = 0;
    DB::listen(static function (QueryExecuted $query) use (&$created, $statement): void {
        if (str_starts_with(strtolower($query->sql), 'create index') && ++$created === $statement) {
            throw new RuntimeException('Injected activity adoption failure.');
        }
    });
}

it('resumes after an interruption between DDL steps with the reviewed fingerprint', function (): void {
    $history = activitySchemaHistory();
    $plan = activitySchemaCoordinator()->plan();
    $pgsql = DB::connection()->getDriverName() === 'pgsql';

    expect($plan->collisions)->toBe([])
        ->and(array_map(static fn ($step): string => $step->id, $plan->steps))->toBe(['batch_uuid', 'timestamps', 'indexes'])
        ->and($plan->expected)->toBe(['batch_uuid' => 1, 'timestamps' => $pgsql ? 2 : 0, 'indexes' => $pgsql ? 6 : 5])
        ->and(Schema::hasColumn('activity_log', 'batch_uuid'))->toBeFalse()
        ->and(DB::table(AdoptionStore::Runs)->count())->toBe(0);

    // The second CREATE INDEX runs in the second index batch; it and its checkpoint roll back together.
    activitySchemaFailOnIndex(2);
    expect(fn () => activitySchemaCoordinator()->apply($plan->fingerprint, 2))
        ->toThrow(RuntimeException::class, 'Injected activity adoption failure.');

    $runId = (string) DB::table(AdoptionStore::Runs)->value('id');
    $progress = activitySchemaCoordinator()->progress($plan);
    $firstIndexBatch = array_slice(array_keys(collect(app(ActivitySchemaInspector::class)->requiredIndexes(DB::connection(), 'activity_log'))->sortKeys()->all()), 0, 2);
    expect(DB::table(AdoptionStore::Runs)->value('status'))->toBe(AdoptionProgress::Failed)
        ->and(Schema::hasColumn('activity_log', 'batch_uuid'))->toBeTrue()
        ->and($progress?->steps['batch_uuid']['complete'])->toBeTrue()
        ->and($progress?->steps['timestamps']['complete'])->toBeTrue()
        ->and($progress?->steps['indexes'])->toBe(['cursor' => ['name' => $firstIndexBatch[1]], 'processed' => 2, 'expected' => $plan->expected['indexes'], 'complete' => false])
        ->and(activitySchemaHistory())->toBe($history);
    $state = app(ActivitySchemaInspector::class)->state(DB::connection(), 'activity_log');
    // Exactly the uncovered indexes after the first committed index batch remain: the failed batch's DDL rolled back.
    // Legacy indexes cover log_name, subject and causer; on PostgreSQL the committed newest-first (created_at, id)
    // index also covers created, so the failing CREATE in batch 2 is the event index on both drivers.
    $covered = $pgsql ? ['_log_name', '_subject', '_causer', '_created'] : ['_log_name', '_subject', '_causer'];
    $rolledBack = array_values(array_filter(
        array_slice(array_keys(collect(app(ActivitySchemaInspector::class)->requiredIndexes(DB::connection(), 'activity_log'))->sortKeys()->all()), 2),
        static fn (string $name): bool => array_filter($covered, static fn (string $suffix): bool => str_ends_with($name, $suffix)) === [],
    ));
    $missing = array_keys($state['missing_indexes']);
    sort($missing);
    expect($state['add_batch_uuid'])->toBeFalse()
        ->and($state['widen_timestamps'])->toBe([])
        ->and($missing)->toBe($rolledBack)
        ->and($rolledBack)->toHaveCount(1)
        ->and(array_column(Schema::getIndexes('activity_log'), 'name'))->not->toContain($rolledBack[0]);
    if ($pgsql) {
        expect(collect(Schema::getColumns('activity_log'))->whereIn('name', ['created_at', 'updated_at'])->pluck('type')->unique()->all())
            ->toBe(['timestamp(6) without time zone']);
    }

    // The committed DDL is an accepted transition, so the reviewed fingerprint still holds.
    expect(activitySchemaCoordinator()->plan()->fingerprint)->toBe($plan->fingerprint);
    $resumed = activitySchemaCoordinator()->apply($plan->fingerprint, 2, $runId);

    expect($resumed->state)->toBe(AdoptionProgress::Applied)
        ->and($resumed->written)->toBe(2)
        ->and($resumed->complete())->toBeTrue()
        ->and(app(ActivitySchemaInspector::class)->state(DB::connection(), 'activity_log')['missing_indexes'])->toBe([])
        ->and(activitySchemaHistory())->toBe($history)
        ->and(activitySchemaCoordinator()->verify()->passed)->toBeTrue()
        ->and(activitySchemaCoordinator()->activate($plan->fingerprint)->state)->toBe(AdoptionProgress::Active);
});

it('adopts through nvl:adopt without changing history and completes every index', function (): void {
    $history = activitySchemaHistory();
    $legacyPlan = app(ActivitySchemaAdopter::class)->plan(SystemActor::Background);
    $run = static function (array $arguments): array {
        $status = Artisan::call('nvl:adopt', ['package' => 'activity', ...$arguments, '--format' => 'json']);

        return ['status' => $status, ...json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
    };

    $planned = $run(['phase' => 'plan']);
    expect($planned['status'])->toBe(0);
    $fingerprint = $planned['fingerprint'];
    expect($run(['phase' => 'verify'])['status'])->toBe(1);

    $applied = $run(['phase' => 'apply', '--fingerprint' => $fingerprint]);
    $verified = $run(['phase' => 'verify']);
    $activated = $run(['phase' => 'activate', '--fingerprint' => $fingerprint]);

    expect($applied['status'])->toBe(0)
        ->and($applied['state'])->toBe(AdoptionProgress::Applied)
        ->and($verified['verification']['passed'])->toBeTrue()
        ->and($activated['state'])->toBe(AdoptionProgress::Active)
        ->and(activitySchemaHistory())->toBe($history)
        ->and(DB::table('activity_log')->whereNotNull('batch_uuid')->count())->toBe(0);

    // The legacy adopter shares the same review: its fingerprint survives and it reports the table ready.
    expect(app(ActivitySchemaAdopter::class)->verify(SystemActor::Background, $legacyPlan['fingerprint'])['ready'])->toBeTrue();

    $required = app(ActivitySchemaInspector::class)->requiredIndexes(DB::connection(), 'activity_log');
    // The legacy log_name, subject and causer indexes already cover those requirements, so only the rest are created.
    $adopted = array_keys(array_filter($required, static fn (string $name): bool => ! str_ends_with($name, '_log_name')
        && ! str_ends_with($name, '_subject') && ! str_ends_with($name, '_causer'), ARRAY_FILTER_USE_KEY));
    sort($adopted);
    expect(activitySchemaAdoptedIndexes())->toBe($adopted)
        ->and(app(ActivitySchemaInspector::class)->state(DB::connection(), 'activity_log'))->toMatchArray([
            'add_batch_uuid' => false, 'widen_timestamps' => [], 'missing_indexes' => [], 'conflicts' => [],
        ]);
    if (DB::connection()->getDriverName() === 'pgsql') {
        $definition = DB::selectOne('SELECT pg_get_indexdef(?::regclass) AS definition', ['activity_log_newest_first_index'])->definition;
        expect($definition)->toEndWith('(created_at DESC NULLS LAST, id DESC)')
            ->and(DB::selectOne("SELECT bool_and(indisvalid) AS valid FROM pg_index WHERE indrelid = 'activity_log'::regclass")->valid)->toBeTrue();
    }
});

it('refuses to resume once history changed behind the reviewed plan', function (): void {
    $plan = activitySchemaCoordinator()->plan();
    activitySchemaFailOnIndex(1);
    expect(fn () => activitySchemaCoordinator()->apply($plan->fingerprint, 2))->toThrow(RuntimeException::class);
    $runId = (string) DB::table(AdoptionStore::Runs)->value('id');

    DB::table('activity_log')->where('id', '019c46ed-4f77-71a8-a741-216bad57d93c')->update(['description' => 'Rewritten history']);

    expect(fn () => activitySchemaCoordinator()->apply($plan->fingerprint, 2, $runId))
        ->toThrow(AdoptionException::class, 'changed since review');
});
