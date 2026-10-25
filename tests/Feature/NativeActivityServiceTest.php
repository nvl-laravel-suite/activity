<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Activity\Contracts\ActivityAuthorization;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityCaptureContext;
use Nvl\Activity\Services\ActivityReader;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Services\TimelineFilter;
use Nvl\Activity\Support\ActivityRecordEnvelope;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Activity\Tests\Stubs\TestActivityCauser;
use Nvl\Activity\Tests\Stubs\TestActivityTimelineSubject;
use Nvl\Support\Enums\SystemActor;

it('exposes a native entry operation and rejects unknown protected fields before storage', function (): void {
    $operation = new ReflectionMethod(ActivityRecorder::class, 'record');
    expect((string) $operation->getParameters()[0]->getType())->toBe('array');
    expect(fn () => app(ActivityRecorder::class)->record(['event' => 'reviewed', 'owner_scope_key' => 'forged'], SystemActor::Background))
        ->toThrow(InvalidArgumentException::class);
    expect(ActivityLog::query()->count())->toBe(0);
});

it('redacts secrets from immutable delivered facts and retains replay identity', function (): void {
    $envelope = new ActivityRecordEnvelope(
        id: (string) Str::uuid(), subject: new ActivitySubjectReference('deleted.subject', 'one'),
        causer: null, event: 'reviewed', logName: 'audit', occurredAt: CarbonImmutable::now(),
        context: ['password' => 'secret', 'nested' => ['token' => 'secret-token', 'state' => 'active']],
        attributes: ['api_key' => 'key', 'title' => 'After'], old: ['api_key' => 'old-key', 'title' => 'Before'],
        scalarActorId: 'deleted-operator',
    );
    $recorder = app(ActivityRecorder::class);
    $record = $recorder->recordEnvelope($envelope);
    expect($record->properties->get('context'))->toBe(['password' => '[REDACTED]', 'nested' => ['token' => '[REDACTED]', 'state' => 'active']])
        ->and($record->properties->get('attributes'))->toBe(['api_key' => '[REDACTED]', 'title' => 'After'])
        ->and($record->properties->get('actor_id'))->toBe('deleted-operator')
        ->and($recorder->recordEnvelope($envelope)->getKey())->toBe($record->getKey())
        ->and(ActivityLog::query()->count())->toBe(1);
});

it('keeps delivery writes inside the host outer transaction', function (): void {
    $envelope = new ActivityRecordEnvelope(
        id: (string) Str::uuid(), subject: new ActivitySubjectReference('deleted.subject', 'one'),
        causer: null, event: 'reviewed', logName: 'audit', occurredAt: CarbonImmutable::now(),
    );
    DB::beginTransaction();
    app(ActivityRecorder::class)->recordEnvelope($envelope);
    DB::rollBack();
    expect(ActivityLog::query()->count())->toBe(0);
    DB::beginTransaction();
    app(ActivityRecorder::class)->recordEnvelope($envelope);
    DB::commit();
    app(ActivityRecorder::class)->recordEnvelope($envelope);
    expect(ActivityLog::query()->count())->toBe(1);
});

it('denies direct recording without a host policy even for an explicit background actor', function (): void {
    $this->app->offsetUnset(ActivityAuthorization::class);
    expect(fn () => app(ActivityRecorder::class)->record(['event' => 'reviewed'], SystemActor::Background))
        ->toThrow(AuthorizationException::class);
    expect(ActivityLog::query()->count())->toBe(0);
});

it('requires explicit capture attribution and restores nested capture scopes after exceptions', function (): void {
    $context = app(ActivityCaptureContext::class);
    expect(fn () => $context->actor())->toThrow(AuthorizationException::class);
    try {
        $context->run(SystemActor::Background, function () use ($context): void {
            expect($context->actor())->toBe(SystemActor::Background);
            throw new RuntimeException('rollback scope');
        });
    } catch (RuntimeException) {
    }
    expect(fn () => $context->actor())->toThrow(AuthorizationException::class);
});

it('returns native paginated models with deterministic bounded timeline reads', function (): void {
    expect(class_exists(ActivityReader::class))->toBeTrue();
    $this->app->bind(ActivityAuthorization::class, static fn () => new class implements ActivityAuthorization
    {
        public function authorize(Authenticatable|SystemActor $actor, string $ability, Model|ActivitySubjectReference|null $subject = null): void {}
    });
    $subject = new ActivitySubjectReference('deleted.subject', 'one');
    foreach (range(1, 5) as $number) {
        app(ActivityRecorder::class)->record(['subject' => $subject, 'event' => 'reviewed', 'context' => ['number' => $number]], SystemActor::Background);
    }
    $reader = app(ActivityReader::class);
    $page = $reader->paginate(['subject_type' => 'deleted.subject', 'subject_id' => 'one', 'per_page' => 2, 'page' => 1], SystemActor::Background);
    expect($page->total())->toBe(5)->and($page->items())->toHaveCount(2)->and($page->items()[0])->toBeInstanceOf(ActivityLog::class);
    $timeline = $reader->timeline($subject, SystemActor::Background, 2);
    expect($timeline)->toHaveCount(2)->and($timeline->pluck('id')->all())->toBe($page->getCollection()->pluck('id')->all());
});

it('authorizes canonical persisted actor facts and rejects transient subjects', function (): void {
    Schema::create('activity_test_causers', function (Blueprint $table): void {
        $table->increments('causer_key');
        $table->string('name');
        $table->string('email');
    });
    $actor = TestActivityCauser::query()->create(['name' => 'Stored', 'email' => 'stored@example.test']);
    $actor->name = 'Forged';
    $this->app->bind(ActivityAuthorization::class, static fn () => new class implements ActivityAuthorization
    {
        public function authorize(Authenticatable|SystemActor $actor, string $ability, Model|ActivitySubjectReference|null $subject = null): void
        {
            if ($actor instanceof Model && $actor->getAttribute('name') !== 'Stored') {
                throw new AuthorizationException('Actor facts were substituted.');
            }
        }
    });
    $record = app(ActivityRecorder::class)->record(['event' => 'reviewed'], $actor);
    expect($record->causer_id)->toBe((string) $actor->getKey());
    expect(fn () => app(ActivityRecorder::class)->record(['event' => 'reviewed', 'subject' => new TestActivityTimelineSubject], $actor))->toThrow(InvalidArgumentException::class);
    $actor->setTable('missing_actors');
    expect(fn () => app(ActivityRecorder::class)->record(['event' => 'reviewed'], $actor))->toThrow(AuthorizationException::class);
    expect(ActivityLog::query()->count())->toBe(1);
});

it('preserves semantic event inference for historical rows without stored event names', function (): void {
    $activity = new ActivityLog(['description' => 'Historical', 'properties' => ['attributes' => ['name' => 'After'], 'old' => ['name' => 'Before']]]);
    expect($activity->semanticEvent())->toBe('updated');
    expect(app(TimelineFilter::class)->shouldIncludeInSignalTimeline($activity))->toBeTrue();
    $activity->properties = [];
    expect($activity->semanticEvent())->toBe('activity_logged');
});
