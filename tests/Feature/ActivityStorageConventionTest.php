<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Activity\Contracts\ActivityAuthorization;
use Nvl\Activity\Exceptions\ActivityRecordingException;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityDoctor;
use Nvl\Activity\Services\ActivityPolicyRegistry;
use Nvl\Activity\Services\ActivityReader;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Support\ActivityEventPolicy;
use Nvl\Activity\Support\ActivityPurgeCriteria;
use Nvl\Activity\Support\ActivityRecordEnvelope;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Activity\Tests\Stubs\TestActivitySubjectWithHasModelActivity;
use Nvl\Activity\Tests\Stubs\TestActivityUser;
use Nvl\Activity\Traits\HasModelActivity;
use Nvl\Support\Enums\SystemActor;

/** Run one scenario with a non-UTC application timezone, as Laravel configures it at boot. */
function activityInApplicationTimezone(string $timezone, Closure $scenario): void
{
    $previous = date_default_timezone_get();
    config()->set('app.timezone', $timezone);
    date_default_timezone_set($timezone);
    try {
        $scenario();
    } finally {
        date_default_timezone_set($previous);
    }
}

/** @return list<mixed> Bindings of every statement the operation ran against the activity log. */
function activityLogBindings(Closure $operation): array
{
    $bindings = [];
    DB::listen(function (QueryExecuted $query) use (&$bindings): void {
        if (str_contains($query->sql, (new ActivityLog)->getTable())) {
            $bindings = [...$bindings, ...$query->bindings];
        }
    });
    $operation();

    return $bindings;
}

test('envelope facts are stored in the application timezone like every other fact', function (): void {
    activityInApplicationTimezone('Europe/Sofia', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-11-11T09:00:00+00:00'));
        $direct = app(ActivityRecorder::class)->record(['event' => 'reviewed'], SystemActor::Background);
        $envelope = new ActivityRecordEnvelope(
            id: (string) Str::uuid(), subject: new ActivitySubjectReference('tasks.task', 'task-1'), causer: null,
            event: 'reviewed', logName: 'audit', occurredAt: CarbonImmutable::parse('2026-11-11T10:00:00.250000+00:00'),
        );

        $fact = app(ActivityRecorder::class)->recordEnvelope($envelope);

        expect($fact->getRawOriginal('created_at'))->toBe('2026-11-11 12:00:00.250000')
            ->and($fact->fresh()->created_at->equalTo($envelope->occurredAt))->toBeTrue()
            ->and(ActivityLog::query()->newestFirst()->pluck('id')->all())->toBe([$fact->id, $direct->id])
            ->and(app(ActivityRecorder::class)->recordEnvelope($envelope)->is($fact))->toBeTrue();
    });
});

test('an envelope stored with the legacy UTC wall clock is recognised on retry without admitting another instant', function (): void {
    activityInApplicationTimezone('Europe/Sofia', function (): void {
        $envelope = fn (string $occurredAt): ActivityRecordEnvelope => new ActivityRecordEnvelope(
            id: '0193f0a0-7c3e-7000-8000-000000000001', subject: new ActivitySubjectReference('tasks.task', 'task-1'), causer: null,
            event: 'reviewed', logName: 'audit', occurredAt: CarbonImmutable::parse($occurredAt),
        );
        $fact = app(ActivityRecorder::class)->recordEnvelope($envelope('2026-11-11T10:00:00.250000+00:00'));
        DB::table($fact->getTable())->where('id', $fact->id)->update(['created_at' => '2026-11-11 10:00:00.250000', 'updated_at' => '2026-11-11 10:00:00.250000']);

        expect(app(ActivityRecorder::class)->recordEnvelope($envelope('2026-11-11T10:00:00.250000+00:00'))->is($fact))->toBeTrue()
            ->and(fn () => app(ActivityRecorder::class)->recordEnvelope($envelope('2026-11-11T08:00:00.250000+00:00')))
            ->toThrow(ActivityRecordingException::class)
            ->and(fn () => app(ActivityRecorder::class)->recordEnvelope($envelope('2026-11-11T12:00:00.250000+00:00')))
            ->toThrow(ActivityRecordingException::class);
    });
});

test('date filters and purge cutoffs compare offset-bearing instants in the application timezone', function (): void {
    $before = ActivityLog::query()->create(['description' => 'before', 'event' => 'created', 'created_at' => '2026-11-10 21:30:00']);
    $after = ActivityLog::query()->create(['description' => 'after', 'event' => 'created', 'created_at' => '2026-11-10 22:30:00']);
    $instant = '2026-11-11T00:00:00+02:00';
    $reader = app(ActivityReader::class);

    expect($reader->paginate(['created_at_from' => $instant], SystemActor::Background)->pluck('id')->all())->toBe([$after->id])
        ->and($reader->paginate(['created_at_to' => CarbonImmutable::parse($instant)], SystemActor::Background)->pluck('id')->all())->toBe([$before->id])
        ->and(ActivityLog::query()->applyIndexFilters(['created_at_from' => new DateTimeImmutable($instant)])->pluck('id')->all())->toBe([$after->id])
        ->and(ActivityLog::query()->withinDateRange(CarbonImmutable::parse($instant), CarbonImmutable::parse('2026-11-11T01:00:00+02:00'))->pluck('id')->all())->toBe([$after->id])
        ->and(ActivityLog::query()->applyPurgeCriteria(new ActivityPurgeCriteria(before: $instant, includeImportant: true))->pluck('id')->all())->toBe([$before->id])
        ->and(ActivityLog::query()->applyPurgeCriteria(new ActivityPurgeCriteria(before: '2026-11-12T00:00:00+02:00', after: $instant, includeImportant: true))->pluck('id')->all())->toBe([$after->id]);
});

test('date filters convert to a non-UTC application timezone', function (): void {
    activityInApplicationTimezone('Europe/Sofia', function (): void {
        $fact = ActivityLog::query()->create(['description' => 'local noon', 'event' => 'created', 'created_at' => '2026-11-11 12:00:00']);
        $reader = app(ActivityReader::class);

        expect($reader->paginate(['created_at_from' => '2026-11-11T10:00:00+00:00'], SystemActor::Background)->pluck('id')->all())->toBe([$fact->id])
            ->and($reader->paginate(['created_at_from' => '2026-11-11T10:00:01+00:00'], SystemActor::Background)->total())->toBe(0)
            ->and((new ActivityPurgeCriteria(before: '2026-11-11T10:00:00+00:00'))->cutoff()->format('Y-m-d H:i:s'))->toBe('2026-11-11 12:00:00');
    });
});

test('subject and causer identifiers are always bound as strings', function (): void {
    Schema::create('test_activity_users', function (Blueprint $table): void {
        $table->id();
    });
    $policies = new ActivityPolicyRegistry;
    $policies->register(new ActivityEventPolicy('created'));
    $policies->register(new ActivityEventPolicy('private', audience: 'subject_or_actor'));
    app()->instance(ActivityPolicyRegistry::class, $policies);
    $this->app->bind(ActivityAuthorization::class, fn () => new class implements ActivityAuthorization
    {
        public function authorize(Authenticatable|SystemActor $actor, string $ability, Model|ActivitySubjectReference|null $subject = null): void
        {
            if ($ability === 'read_all') {
                throw new AuthorizationException;
            }
        }
    });
    $actor = TestActivityUser::query()->create();
    $fact = ActivityLog::query()->create(['description' => 'row', 'event' => 'created', 'subject_type' => 'record', 'subject_id' => '5', 'causer_type' => 'record', 'causer_id' => '7']);
    $reader = app(ActivityReader::class);
    $reference = new ActivitySubjectReference('record', 5);

    $bindings = activityLogBindings(function () use ($reader, $reference, $actor, $fact): void {
        expect($reader->paginate(['subject_type' => 'record', 'subject_id' => 5, 'causer_id' => 7], $actor)->pluck('id')->all())->toBe([$fact->id])
            ->and($reader->paginate(['subjects' => [$reference], 'subject_or_causer' => $reference], $actor)->pluck('id')->all())->toBe([$fact->id])
            ->and($reader->timeline($reference, $actor)->pluck('id')->all())->toBe([$fact->id])
            ->and(ActivityLog::query()->forSubject('record', [5, 6])->whereSubjectIds([5])->whereCauserIds([7])->pluck('id')->all())->toBe([$fact->id]);
    });

    expect($bindings)->not->toBeEmpty()->and(array_filter($bindings, is_int(...)))->toBe([]);
});

test('the subject relation binds integer keys as strings', function (): void {
    Schema::create('activity_mapping_subjects', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
    });
    $subject = TestActivitySubjectWithHasModelActivity::query()->create(['name' => 'Subject']);
    $fact = ActivityLog::query()->create(['description' => 'row', 'event' => 'created', 'subject_type' => $subject->getMorphClass(), 'subject_id' => (string) $subject->getKey()]);
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        if (str_contains($query->sql, (new ActivityLog)->getTable())) {
            $statements[] = $query;
        }
    });

    expect($subject->activitiesAsSubject()->pluck('id')->all())->toBe([$fact->id])
        ->and($subject->newQuery()->with('activitiesAsSubject')->sole()->activitiesAsSubject->pluck('id')->all())->toBe([$fact->id]);

    expect($statements)->toHaveCount(2);
    foreach ($statements as $statement) {
        expect($statement->bindings)->toContain((string) $subject->getKey())
            ->and(array_filter($statement->bindings, is_int(...)))->toBe([]);
    }
});

test('finding a fact by a malformed identifier returns null without querying', function (): void {
    ActivityLog::query()->create(['description' => 'row', 'event' => 'created']);

    $bindings = activityLogBindings(function (): void {
        expect(app(ActivityReader::class)->find('not-a-uuid', SystemActor::Background))->toBeNull();
    });

    expect($bindings)->toBe([]);
});

test('newest first ordering is served by the timestamp index and keeps undated legacy rows last', function (): void {
    $undated = ActivityLog::query()->create(['description' => 'undated', 'event' => 'created']);
    DB::table($undated->getTable())->where('id', $undated->id)->update(['created_at' => null]);
    $older = ActivityLog::query()->create(['description' => 'older', 'event' => 'created', 'created_at' => '2026-11-10 10:00:00']);
    $newer = ActivityLog::query()->create(['description' => 'newer', 'event' => 'created', 'created_at' => '2026-11-11 10:00:00']);
    $query = ActivityLog::query()->newestFirst();

    expect($query->pluck('id')->all())->toBe([$newer->id, $older->id, $undated->id]);

    if ($undated->getConnection()->getDriverName() === 'sqlite') {
        $plan = collect($undated->getConnection()->select('EXPLAIN QUERY PLAN '.$query->toSql()))->pluck('detail')->implode(' | ');
        expect($plan)->not->toContain('TEMP B-TREE');
    }

    if ($undated->getConnection()->getDriverName() === 'pgsql') {
        $plan = $undated->getConnection()->transaction(function () use ($undated, $query): string {
            $undated->getConnection()->statement('SET LOCAL enable_seqscan = off');
            $undated->getConnection()->statement('SET LOCAL enable_bitmapscan = off');

            return collect($undated->getConnection()->select('EXPLAIN '.$query->toSql()))->pluck('QUERY PLAN')->implode(' | ');
        });
        $indexes = fn (): bool => collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'schema.indexes')['passed'];
        expect($query->toSql())->not->toContain('case')
            ->and($plan)->toContain($undated->getTable().'_newest_first_index')->not->toContain('Sort')
            ->and($indexes())->toBeTrue();
        $undated->getConnection()->statement('DROP INDEX '.$undated->getTable().'_newest_first_index');
        expect($indexes())->toBeFalse();
    }
});

test('subject existence queries bind integer keys as text', function (): void {
    Schema::create('activity_mapping_subjects', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
    });
    $subject = TestActivitySubjectWithHasModelActivity::query()->create(['name' => 'Subject']);
    $silent = TestActivitySubjectWithHasModelActivity::query()->create(['name' => 'Silent']);
    ActivityLog::query()->create(['description' => 'row', 'event' => 'created', 'subject_type' => $subject->getMorphClass(), 'subject_id' => (string) $subject->getKey()]);

    expect(TestActivitySubjectWithHasModelActivity::query()->whereHas('activitiesAsSubject')->pluck('id')->all())->toBe([$subject->getKey()])
        ->and(TestActivitySubjectWithHasModelActivity::query()->whereDoesntHave('activitiesAsSubject')->pluck('id')->all())->toBe([$silent->getKey()])
        ->and(TestActivitySubjectWithHasModelActivity::query()->withCount('activitiesAsSubject')->orderBy('id')->pluck('activities_as_subject_count')->all())->toBe([1, 0]);
});

test('uuid subject existence queries and joined activity ordering qualify columns safely', function (): void {
    Schema::create('activity_uuid_subjects', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('name')->nullable();
        $table->timestamps();
    });

    $uuidModel = new class extends Model
    {
        use HasModelActivity;
        use HasUuids;

        protected $table = 'activity_uuid_subjects';

        protected $guarded = [];
    };

    $subject = $uuidModel->newQuery()->create(['name' => 'UUID Subject']);
    $silent = $uuidModel->newQuery()->create(['name' => 'UUID Silent']);
    $fact = ActivityLog::query()->create([
        'description' => 'uuid row',
        'event' => 'created',
        'subject_type' => $subject->getMorphClass(),
        'subject_id' => (string) $subject->getKey(),
    ]);

    expect($uuidModel->newQuery()->whereHas('activitiesAsSubject')->pluck('id')->all())->toBe([(string) $subject->getKey()])
        ->and($uuidModel->newQuery()->whereDoesntHave('activitiesAsSubject')->pluck('id')->all())->toBe([(string) $silent->getKey()])
        ->and($uuidModel->newQuery()->withCount('activitiesAsSubject')->whereKey($subject->getKey())->value('activities_as_subject_count'))->toBe(1);

    $activityTable = (new ActivityLog)->getTable();
    $joinedIds = ActivityLog::query()
        ->leftJoin('activity_uuid_subjects', 'activity_uuid_subjects.name', '=', "{$activityTable}.description")
        ->where("{$activityTable}.id", $fact->id)
        ->select("{$activityTable}.*")
        ->newestFirst()
        ->pluck("{$activityTable}.id")
        ->all();

    expect($joinedIds)->toBe([$fact->id]);
});
