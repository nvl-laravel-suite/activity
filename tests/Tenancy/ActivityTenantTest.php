<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Ownership\ActivityOwnershipGuard;
use Nvl\Activity\Services\ActivityCaptureContext;
use Nvl\Activity\Services\ActivityReader;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Services\ActivityRelationLoader;
use Nvl\Activity\Services\MappingRegistry;
use Nvl\Activity\Support\ActivityRecordEnvelope;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Activity\Tests\Fixtures\OwnershipActor;
use Nvl\Activity\Tests\Fixtures\OwnershipCompany;
use Nvl\Activity\Tests\Fixtures\OwnershipSubject;
use Nvl\Activity\Tests\Fixtures\UnknownActivitySubject;
use Nvl\Activity\Tests\Stubs\TestActivityMapping;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

function activityOwner(int $id, Closure $operation): mixed
{
    $actor = OwnershipActor::query()->firstOrFail();

    return app(OwnershipRunner::class)->run(OwnershipCompany::query()->findOrFail($id), $actor,
        fn () => app(ActivityCaptureContext::class)->run($actor, $operation));
}

function activityOwnedSubject(int $id = 70): OwnershipSubject
{
    return app(OwnershipWriter::class)->create(OwnershipSubject::class,
        fn (OwnershipAssignment $assignment): OwnershipSubject => OwnershipSubject::query()->create([...$assignment->attributes(), 'id' => $id, 'name' => 'Owned']));
}

function activityOwnedFact(?OwnershipSubject $subject = null): ActivityLog
{
    return app(ActivityRecorder::class)->record(['subject' => $subject ?? new ActivitySubjectReference('external', 'same-id'), 'event' => 'reviewed'], OwnershipActor::query()->firstOrFail());
}

test('selected adoption installs immutable native ownership storage', function (): void {
    expect(DB::getSchemaBuilder()->hasColumns((new ActivityLog)->getTable(), ['owner_scope_key', 'owner_reference']))->toBeTrue();
    $fact = activityOwner(7, fn () => activityOwnedFact());
    expect($fact->owner_scope_key)->not->toBeEmpty()->and($fact->owner_reference)->not->toBeEmpty();
});

test('a shared subject reference does not merge owner timelines', function (): void {
    $a = activityOwner(7, fn () => activityOwnedFact());
    $b = activityOwner(8, fn () => activityOwnedFact());
    $rows = activityOwner(7, fn () => app(ActivityReader::class)->timeline(new ActivitySubjectReference('external', 'same-id'), SystemActor::Background));
    expect($rows->pluck('id')->all())->toBe([$a->id])->not->toContain($b->id);
});

test('a replay cannot resolve an activity ID owned by another owner', function (): void {
    $envelope = new ActivityRecordEnvelope((string) Str::uuid(), new ActivitySubjectReference('deleted', 'one'), null, 'reviewed', 'audit', CarbonImmutable::now());
    activityOwner(7, fn () => app(ActivityRecorder::class)->recordEnvelope($envelope));
    expect(fn () => activityOwner(8, fn () => app(ActivityRecorder::class)->recordEnvelope($envelope)))->toThrow(UniqueConstraintViolationException::class);
    activityOwner(7, fn () => expect(ActivityLog::query()->count())->toBe(1));
    activityOwner(8, fn () => expect(ActivityLog::query()->count())->toBe(0));
});

test('unresolved recording and reads fail closed', function (): void {
    expect(fn () => activityOwnedFact())->toThrow(OwnershipViolation::class);
    expect(fn () => app(ActivityReader::class)->paginate([], SystemActor::Background))->toThrow(OwnershipViolation::class);
});

test('retention jobs preserve their ownership envelope across serialization', function (): void {
    $job = activityOwner(7, fn () => new PurgeActivityLogsJob(90));
    $restored = unserialize(serialize($job));
    expect($restored->ownershipJobEnvelope()->toArray())->toBe($job->ownershipJobEnvelope()->toArray());
});

test('background facts still require an admitted owner partition', function (): void {
    $fact = activityOwner(7, fn () => app(ActivityRecorder::class)->record(['event' => 'background'], SystemActor::Background));
    expect($fact->properties->get('source'))->toBe('system');
    activityOwner(8, fn () => expect(ActivityLog::query()->count())->toBe(0));
});

test('automatic capture stamps rows and does not trust supplied subject ownership', function (): void {
    app(MappingRegistry::class)->register(new TestActivityMapping(OwnershipSubject::class));
    $subject = activityOwner(7, fn () => activityOwnedSubject());
    expect(fn () => activityOwner(8, fn () => app(ActivityRecorder::class)->record(['subject' => $subject, 'event' => 'forged'], SystemActor::Background)))->toThrow(OwnershipViolation::class);
    activityOwner(7, fn () => expect(ActivityLog::query()->pluck('event')->all())->toBe(['created']));
});

test('hydration reloads the canonical subject and rejects a preloaded foreign event', function (): void {
    $fact = activityOwner(7, fn () => activityOwnedFact(activityOwnedSubject()));
    activityOwner(7, fn () => $fact->setRelation('subject', new OwnershipSubject));
    expect(fn () => activityOwner(8, fn () => app(ActivityRelationLoader::class)->load(new Collection([$fact]))))->toThrow(OwnershipViolation::class);
});

test('native subject relations cannot hydrate a foreign registered subject', function (): void {
    $subject = activityOwner(7, fn () => activityOwnedSubject());
    $fact = activityOwner(8, fn () => app(ActivityRecorder::class)->record(['subject' => new ActivitySubjectReference($subject->getMorphClass(), $subject->getKey()), 'event' => 'imported'], SystemActor::Background));
    activityOwner(8, fn () => expect($fact->fresh()->subject)->toBeNull());
});

test('native subject relations deny unknown types before model construction', function (): void {
    $fact = activityOwner(7, fn () => app(ActivityRecorder::class)->record(['subject' => new ActivitySubjectReference(UnknownActivitySubject::class, 'one'), 'event' => 'imported'], SystemActor::Background));
    expect(fn () => activityOwner(7, fn () => $fact->fresh()->subject))->toThrow(OwnershipViolation::class);
});

test('trusted native relations are readmitted after an owner context transition', function (): void {
    $fact = activityOwner(7, function (): ActivityLog {
        $fact = activityOwnedFact(activityOwnedSubject());
        expect($fact->fresh()->subject)->not->toBeNull();

        return $fact->fresh();
    });
    expect(fn () => activityOwner(8, fn () => $fact->subject))->toThrow(OwnershipViolation::class);
    activityOwner(7, fn () => expect($fact->subject)->not->toBeNull());
});

test('combined native eager loads retain both relations in either order', function (): void {
    activityOwner(7, function (): void {
        activityOwnedFact(activityOwnedSubject());
        foreach ([['subject', 'causer'], ['causer', 'subject']] as $relations) {
            $row = ActivityLog::query()->with($relations)->firstOrFail();
            expect($row->subject)->toBeInstanceOf(OwnershipSubject::class)->and($row->causer)->toBeInstanceOf(OwnershipActor::class);
        }
    });
});

test('subject admission rejects foreign ownership before returning its data', function (): void {
    $subject = activityOwner(7, fn () => activityOwnedSubject());
    expect(fn () => activityOwner(8, fn () => app(ActivityReader::class)->timeline($subject, SystemActor::Background)))->toThrow(OwnershipViolation::class);
});

test('supplied subjects cannot replace canonical storage or a persisted identity', function (): void {
    activityOwner(7, function (): void {
        $subject = activityOwnedSubject();
        $subject->setTable('activity_test_actors');
        expect(fn () => activityOwnedFact($subject))->toThrow(OwnershipViolation::class);
    });
});

test('adopted Activity storage denies reads when its ownership runtime is disabled', function (): void {
    activityOwner(7, fn () => activityOwnedFact());
    config()->set('nvl-ownership.enabled', false);
    expect(fn () => ActivityLog::query()->get())->toThrow(OwnershipViolation::class);
});

test('owner retention dispatch captures its bounded ownership envelope', function (): void {
    $job = activityOwner(7, fn () => new PurgeActivityLogsJob(90));
    expect($job->ownershipJobEnvelope())->not->toBeNull();
    expect(fn () => new PurgeActivityLogsJob(90))->toThrow(OwnershipViolation::class);
});

test('automatic deletion retains immutable ownership after its subject is removed', function (): void {
    app(MappingRegistry::class)->register(new TestActivityMapping(OwnershipSubject::class));
    activityOwner(7, function (): void {
        $subject = activityOwnedSubject();
        $subject->delete();
        expect(ActivityLog::query()->where('event', 'deleted')->count())->toBe(1);
    });
});

test('configured global causers use a fixed safe identity projection', function (): void {
    activityOwner(7, function (): void {
        $fact = activityOwnedFact();
        expect($fact->fresh()->causer->getAttributes())->toHaveKeys(['id', 'name', 'email'])->not->toHaveKey('password');
    });
});

test('causer association rejects a changed identity without recording a fact', function (): void {
    activityOwner(7, function (): void {
        $actor = OwnershipActor::query()->firstOrFail();
        $actor->id = 99;
        expect(fn () => app(ActivityRecorder::class)->record(['event' => 'forged'], $actor))->toThrow(AuthorizationException::class);
        expect(ActivityLog::query()->count())->toBe(0);
    });
});

test('global causer association requires one canonical persisted identity', function (): void {
    activityOwner(7, function (): void {
        $actor = new OwnershipActor;
        $actor->id = 1;
        expect(fn () => app(ActivityRecorder::class)->record(['event' => 'forged'], $actor))->toThrow(AuthorizationException::class);
    });
});

test('identity presentation cannot reclassify a registered owned subject as global', function (): void {
    $subject = activityOwner(7, fn () => activityOwnedSubject());
    config()->set('nvl-activity.causer_suggestions.model', OwnershipSubject::class);
    expect(fn () => activityOwner(8, fn () => app(ActivityOwnershipGuard::class)->canonicalCauser($subject)))->toThrow(OwnershipViolation::class);
});

test('timeline host reads apply canonical ownership before hydration', function (): void {
    $subject = activityOwner(7, fn () => activityOwnedSubject());
    expect(fn () => activityOwner(8, fn () => app(ActivityReader::class)->timeline($subject, SystemActor::Background)))->toThrow(OwnershipViolation::class);
});

test('serialized retention runs in its producer context and restores the worker owner', function (): void {
    $job = activityOwner(7, function (): PurgeActivityLogsJob {
        ActivityLog::query()->create(['description' => 'old A', 'created_at' => now()->subDays(120)]);

        return unserialize(serialize(new PurgeActivityLogsJob(90)));
    });
    activityOwner(8, function () use ($job): void {
        ActivityLog::query()->create(['description' => 'old B', 'created_at' => now()->subDays(120)]);
        app(Dispatcher::class)->dispatchSync($job);
        expect(ActivityLog::query()->pluck('description')->all())->toBe(['old B']);
    });
    activityOwner(7, fn () => expect(ActivityLog::query()->count())->toBe(0));
});

test('unresolved ownership cannot capture a deferred queue envelope', function (): void {
    expect(fn () => new PurgeActivityLogsJob(90))->toThrow(OwnershipViolation::class);
});

test('activity ownership remains immutable after persistence', function (): void {
    activityOwner(7, function (): void {
        $fact = activityOwnedFact();
        $fact->owner_scope_key = 'forged';
        expect(fn () => $fact->save())->toThrow(OwnershipViolation::class);
    });
});

test('unregistered subject types are not hydrated by the dedicated loader', function (): void {
    activityOwner(7, function (): void {
        $fact = app(ActivityRecorder::class)->record(['subject' => new ActivitySubjectReference(UnknownActivitySubject::class, 'one'), 'event' => 'imported'], SystemActor::Background);
        app(ActivityRelationLoader::class)->load(new Collection([$fact]));
        expect($fact->getRelation('subject'))->toBeNull();
    });
});

test('scoped capture state is empty after a lifecycle reset', function (): void {
    $before = app(ActivityCaptureContext::class);
    $this->app->forgetScopedInstances();
    $after = app(ActivityCaptureContext::class);
    expect($before)->not->toBe($after)->and(fn () => $after->actor())->toThrow(AuthorizationException::class);
});

test('canonical hydration keeps protected row loading bounded across fixture sizes', function (): void {
    activityOwner(7, function (): void {
        $subject = activityOwnedSubject();
        foreach (range(1, 25) as $_) {
            activityOwnedFact($subject);
        }
        $counts = [];
        foreach ([1, 25] as $limit) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $rows = ActivityLog::query()->limit($limit)->get();
            app(ActivityRelationLoader::class)->load($rows);
            $counts[] = count(array_filter(DB::getQueryLog(), static fn (array $query): bool => preg_match('/^select .* from ["`]*(nvl_activity_log|activity_test_subjects|activity_test_actors)["`]* /i', $query['query']) === 1));
            DB::disableQueryLog();
        }
        expect($counts[1])->toBe($counts[0]);
    });
});
