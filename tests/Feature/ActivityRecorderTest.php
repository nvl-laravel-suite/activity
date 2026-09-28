<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Activity\Enums\ActivityEvent;
use Nvl\Activity\Enums\ActivitySource;
use Nvl\Activity\Enums\ActivityVisibility;
use Nvl\Activity\Exceptions\ActivityRecordingException;
use Nvl\Activity\Facades\ActivityLog as ActivityLogFacade;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Support\ActivityCauserReference;
use Nvl\Activity\Support\ActivityRecordEnvelope;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Activity\Tenancy\ActivityOwnershipGuard;
use Nvl\Activity\Tests\Stubs\TestActivityCauser;
use Nvl\Activity\Tests\Stubs\TestActivityUser;

test('record envelopes retain their exact replay payload and native morph identities', function (): void {
    $id = (string) Str::uuid();
    $envelope = new ActivityRecordEnvelope(
        id: $id,
        subject: new ActivitySubjectReference('tasks.task', 'task-1'),
        causer: new ActivityCauserReference('users', 'user-1'),
        event: 'task.updated',
        logName: 'tasks',
        occurredAt: CarbonImmutable::parse('2026-09-28T10:11:12.123456+03:00'),
        context: ['state' => 'active'],
        attributes: ['title' => 'After'],
        old: ['title' => 'Before'],
    );

    $restored = ActivityRecordEnvelope::fromArray(json_decode(json_encode($envelope->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
    $activity = ActivityLogFacade::recordEnvelope($restored);
    $replayed = ActivityLogFacade::recordEnvelope($restored);

    expect($restored->toArray())->toBe($envelope->toArray())
        ->and($activity)->toBeInstanceOf(ActivityLog::class)
        ->and($replayed->getKey())->toBe($id)
        ->and(ActivityLog::query()->count())->toBe(1)
        ->and($activity->subject_type)->toBe('tasks.task')
        ->and($activity->subject_id)->toBe('task-1')
        ->and($activity->causer_type)->toBe('users')
        ->and($activity->causer_id)->toBe('user-1')
        ->and($activity->event)->toBe('task.updated')
        ->and($activity->log_name)->toBe('tasks')
        ->and($activity->properties->get('context'))->toBe(['state' => 'active'])
        ->and($activity->properties->get('attributes'))->toBe(['title' => 'After'])
        ->and($activity->properties->get('old'))->toBe(['title' => 'Before'])
        ->and($activity->properties->get('occurred_at'))->toBe('2026-09-28T10:11:12.123456+03:00');
});

test('record envelopes reject reuse of an activity ID for a changed payload', function (): void {
    $id = (string) Str::uuid();
    $payload = [
        'id' => $id,
        'subject' => ['type' => 'tasks.task', 'id' => 'task-1'],
        'causer' => null,
        'event' => 'task.updated',
        'logName' => 'tasks',
        'occurredAt' => '2026-09-28T10:11:12.000000+03:00',
        'context' => ['state' => 'active'],
        'attributes' => null,
        'old' => null,
        'scalarActorId' => null,
    ];

    ActivityLogFacade::recordEnvelope(ActivityRecordEnvelope::fromArray($payload));
    $payload['context'] = ['state' => 'closed'];

    expect(fn () => ActivityLogFacade::recordEnvelope(ActivityRecordEnvelope::fromArray($payload)))
        ->toThrow(ActivityRecordingException::class);
    expect(ActivityLog::query()->count())->toBe(1);
});

test('record envelopes reject an existing row whose stored business payload changed', function (): void {
    $envelope = new ActivityRecordEnvelope(
        id: (string) Str::uuid(),
        subject: new ActivitySubjectReference('tasks.task', 'task-1'),
        causer: null,
        event: 'task.updated',
        logName: 'tasks',
        occurredAt: CarbonImmutable::parse('2026-09-28T10:11:12.000000+00:00'),
        context: ['state' => 'active'],
    );
    $activity = ActivityLogFacade::recordEnvelope($envelope);
    $activity->properties = $activity->properties->put('context', ['state' => 'closed']);
    $activity->save();

    expect(fn () => ActivityLogFacade::recordEnvelope($envelope))->toThrow(ActivityRecordingException::class);
});

test('record envelopes reject an existing row with a different timeline timestamp', function (): void {
    $envelope = new ActivityRecordEnvelope(
        id: (string) Str::uuid(),
        subject: new ActivitySubjectReference('tasks.task', 'task-1'),
        causer: null,
        event: 'task.updated',
        logName: 'tasks',
        occurredAt: CarbonImmutable::parse('2026-09-28T10:11:12.000000+00:00'),
    );
    $activity = ActivityLogFacade::recordEnvelope($envelope);
    $activity->created_at = CarbonImmutable::parse('2026-09-29T10:11:12.000000+00:00');
    $activity->save();

    expect(fn () => ActivityLogFacade::recordEnvelope($envelope))->toThrow(ActivityRecordingException::class);
});

test('record envelopes reject malformed serialized payloads', function (): void {
    $payload = [
        'id' => (string) Str::uuid(),
        'subject' => ['type' => 'tasks.task', 'id' => 'task-1'],
        'causer' => null,
        'event' => 'task.updated',
        'logName' => 'tasks',
        'occurredAt' => 'tomorrow',
        'context' => [],
        'attributes' => null,
        'old' => null,
        'scalarActorId' => null,
    ];

    expect(fn () => ActivityRecordEnvelope::fromArray($payload))->toThrow(InvalidArgumentException::class);
});

test('record envelopes reject malformed persisted fields and references', function (): void {
    $payload = [
        'id' => (string) Str::uuid(),
        'subject' => ['type' => 'tasks.task', 'id' => 'task-1'],
        'causer' => null,
        'event' => 'task.updated',
        'logName' => 'tasks',
        'occurredAt' => '2026-09-28T10:11:12.000000+00:00',
        'context' => [],
        'attributes' => null,
        'old' => null,
        'scalarActorId' => null,
    ];

    $invalidPayloads = [
        array_diff_key($payload, ['old' => true]),
        array_replace($payload, ['id' => 123]),
        array_replace($payload, ['id' => 'not-a-uuid']),
        array_replace($payload, ['event' => '']),
        array_replace($payload, ['subject' => 'tasks.task']),
        array_replace($payload, ['subject' => ['type' => 'tasks.task', 'id' => []]]),
        array_replace($payload, ['context' => 'not-a-record']),
        array_replace($payload, ['context' => ['nested' => new stdClass]]),
        array_replace($payload, ['scalarActorId' => ' ']),
        array_replace($payload, ['causer' => ['type' => 'users', 'id' => 'user-1'], 'scalarActorId' => 'user-1']),
    ];

    foreach ($invalidPayloads as $invalidPayload) {
        expect(fn () => ActivityRecordEnvelope::fromArray($invalidPayload))
            ->toThrow(InvalidArgumentException::class);
    }
});

test('record envelopes reject metadata that cannot retain a stable JSON shape', function (): void {
    $base = [
        'id' => (string) Str::uuid(),
        'subject' => new ActivitySubjectReference('tasks.task', 'task-1'),
        'causer' => null,
        'event' => 'task.updated',
        'logName' => 'tasks',
        'occurredAt' => CarbonImmutable::parse('2026-09-28T10:11:12.000000+00:00'),
    ];

    foreach ([['first', 'second'], ['object' => new stdClass]] as $context) {
        expect(fn () => new ActivityRecordEnvelope(...$base, context: $context))
            ->toThrow(InvalidArgumentException::class);
    }

    expect(fn () => new ActivityRecordEnvelope(...$base, context: ['nonfinite' => INF]))
        ->toThrow(InvalidArgumentException::class);
});

test('record envelopes accept JSON object keys in any order', function (): void {
    $payload = [
        'id' => (string) Str::uuid(),
        'subject' => ['id' => 'task-1', 'type' => 'tasks.task'],
        'causer' => null,
        'event' => 'task.updated',
        'logName' => 'tasks',
        'occurredAt' => '2026-09-28T10:11:12.000000+00:00',
        'context' => [],
        'attributes' => null,
        'old' => null,
        'scalarActorId' => null,
    ];

    $restored = ActivityRecordEnvelope::fromArray(array_reverse($payload, true));

    expect($restored->subject->type)->toBe('tasks.task')
        ->and($restored->subject->id)->toBe('task-1');
});

test('record envelope retries tolerate reordered JSON object keys', function (): void {
    $payload = [
        'id' => (string) Str::uuid(),
        'subject' => ['type' => 'tasks.task', 'id' => 'task-1'],
        'causer' => null,
        'event' => 'task.updated',
        'logName' => 'tasks',
        'occurredAt' => '2026-09-28T10:11:12.000000+00:00',
        'context' => ['before' => 'open', 'after' => 'closed'],
        'attributes' => null,
        'old' => null,
        'scalarActorId' => null,
    ];
    ActivityLogFacade::recordEnvelope(ActivityRecordEnvelope::fromArray($payload));
    $payload['context'] = ['after' => 'closed', 'before' => 'open'];

    expect(ActivityLogFacade::recordEnvelope(ActivityRecordEnvelope::fromArray($payload))->getKey())->toBe($payload['id'])
        ->and(ActivityLog::query()->count())->toBe(1);
});

test('the canonical writer records structured scalar actors and caller owned batches', function (): void {
    $ambientActor = new TestActivityUser;
    $ambientActor->forceFill(['id' => 99]);
    $this->actingAs($ambientActor);
    $batchUuid = (string) Str::uuid();

    $activity = app(ActivityRecorder::class)->record(
        subject: null,
        event: 'consumer.semantic_event',
        description: 'Consumer semantic event',
        context: ['reason' => 'manual review'],
        actor: 'operator-1',
        logName: 'consumer',
        batchUuid: $batchUuid,
    );

    expect($activity)->toBeInstanceOf(ActivityLog::class)
        ->and($activity?->log_name)->toBe('consumer')
        ->and($activity?->batch_uuid)->toBe($batchUuid)
        ->and($activity?->causer_id)->toBeNull()
        ->and($activity?->causer_type)->toBeNull()
        ->and($activity?->properties?->get('source'))->toBe(ActivitySource::User->value)
        ->and($activity?->properties?->get('actor_id'))->toBe('operator-1')
        ->and($activity?->properties?->get('context'))->toBe(['reason' => 'manual review']);
});

test('the canonical writer records model-free subject references without reading a subject table', function (): void {
    $subject = new ActivitySubjectReference(' domain.resource ', ' resource-42 ');
    app(ActivityOwnershipGuard::class)->attributes();
    DB::flushQueryLog();
    DB::enableQueryLog();

    $activity = ActivityLogFacade::recordForSubjectReference(
        subject: $subject,
        event: 'consumer.reference_recorded',
        context: ['reason' => 'external subject'],
        actor: 'operator-1',
        importance: 'important',
    );
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($activity)->toBeInstanceOf(ActivityLog::class)
        ->and($activity?->subject_type)->toBe('domain.resource')
        ->and($activity?->subject_id)->toBe('resource-42')
        ->and($activity?->properties?->get('context'))->toBe(['reason' => 'external subject'])
        ->and($activity?->properties?->get('actor_id'))->toBe('operator-1')
        ->and($activity?->properties?->get('importance'))->toBe('important')
        ->and($queries)->toHaveCount(1)
        ->and(mb_strtolower($queries[0]['query']))->toStartWith('insert');
});

test('the canonical writer accepts package owned activity events', function (): void {
    $activity = app(ActivityRecorder::class)->record(
        subject: null,
        event: ActivityEvent::Triggered,
    );

    expect($activity?->event)->toBe(ActivityEvent::Triggered->value)
        ->and($activity?->description)->toBe(ActivityEvent::Triggered->value);
});

test('package owned update events infer saved model changes without a description', function (): void {
    $subject = ActivityLog::query()->create([
        'log_name' => 'recording-subject',
        'description' => 'Before',
        'event' => ActivityEvent::Created,
    ]);
    $subject->timestamps = false;
    $subject->forceFill(['description' => 'After'])->save();

    $activity = app(ActivityRecorder::class)->record(
        subject: $subject,
        event: ActivityEvent::Updated,
    );

    expect($activity?->event)->toBe(ActivityEvent::Updated->value)
        ->and($activity?->description)->toBe(ActivityEvent::Updated->value)
        ->and($activity?->properties?->get('attributes'))->toBe(['description' => 'After'])
        ->and($activity?->properties?->get('old'))->toBe(['description' => 'Before']);
});

test('the canonical writer normalizes caller supplied log names and descriptions', function (): void {
    $activity = app(ActivityRecorder::class)->record(
        subject: null,
        event: 'consumer.semantic_event',
        description: '  Consumer semantic event  ',
        logName: '  consumer  ',
    );

    expect($activity?->log_name)->toBe('consumer')
        ->and($activity?->description)->toBe('Consumer semantic event');
});

test('model actors use the native polymorphic causer relation', function (): void {
    $ambientActor = new TestActivityUser;
    $ambientActor->forceFill(['id' => 99]);
    $this->actingAs($ambientActor);
    $actor = new TestActivityCauser;
    $actor->forceFill(['causer_key' => 42]);

    $activity = app(ActivityRecorder::class)->record(
        subject: null,
        event: 'reviewed',
        description: 'Reviewed',
        actor: $actor,
    );

    expect($activity?->causer_type)->toBe($actor->getMorphClass())
        ->and($activity?->causer_id)->toEqual('42')
        ->and($activity?->properties?->get('source'))->toBe(ActivitySource::User->value);
});

test('anonymous and blank scalar actors are system originated', function (?string $actor): void {
    $ambientActor = new TestActivityUser;
    $ambientActor->forceFill(['id' => 99]);
    $this->actingAs($ambientActor);

    $activity = app(ActivityRecorder::class)->record(
        subject: null,
        event: 'synchronized',
        description: 'Synchronized',
        actor: $actor,
    );

    expect($activity?->causer_id)->toBeNull()
        ->and($activity?->causer_type)->toBeNull()
        ->and($activity?->properties?->get('source'))->toBe(ActivitySource::System->value);
})->with([
    'null actor' => null,
    'blank actor' => '',
    'whitespace actor' => '   ',
]);

test('ambient authentication never changes system purge classification', function (): void {
    $ambientActor = new TestActivityUser;
    $ambientActor->forceFill(['id' => 99]);
    $this->actingAs($ambientActor);

    $activity = app(ActivityRecorder::class)->record(
        subject: null,
        event: 'synchronized',
        description: 'System synchronization',
        actor: null,
    );
    $activity?->forceFill([
        'created_at' => now()->subDays(120),
        'updated_at' => now()->subDays(120),
    ])->save();

    expect(PurgeActivityLogsJob::countPurgeable(90, systemOnly: true))->toBe(1)
        ->and($activity?->causer_id)->toBeNull()
        ->and($activity?->properties?->get('source'))->toBe(ActivitySource::System->value);
});

test('blank source and visibility overrides fall back to canonical defaults', function (): void {
    $activity = app(ActivityRecorder::class)->record(
        subject: null,
        event: 'synchronized',
        description: 'Synchronized',
        source: ' ',
        visibility: ' ',
        importance: ' ',
    );

    expect($activity?->properties?->get('source'))->toBe(ActivitySource::System->value)
        ->and($activity?->properties?->get('visibility'))->toBe(ActivityVisibility::Timeline->value)
        ->and($activity?->properties?->get('importance'))->toBe('normal');
});

test('caller owned activity batch identifiers must be valid uuids', function (): void {
    app(ActivityRecorder::class)->record(
        subject: null,
        event: 'synchronized',
        description: 'Synchronized',
        batchUuid: 'not-a-uuid',
    );
})->throws(ActivityRecordingException::class, 'Activity batch identifiers must be valid UUIDs.');

test('unsupported metadata classifications are rejected instead of becoming visible by default', function (
    string $field,
): void {
    $arguments = [
        'subject' => null,
        'event' => 'synchronized',
        'description' => 'Synchronized',
        $field => 'unsupported-value',
    ];

    try {
        app(ActivityRecorder::class)->record(...$arguments);
    } catch (ActivityRecordingException $exception) {
        expect($exception->responseCode())->toBe('invalid_activity_metadata')
            ->and($exception->suggestedStatus())->toBe(422)
            ->and($exception->publicContext())->toBe(['field' => $field]);

        throw $exception;
    }
})->with([
    'source' => ['source'],
    'visibility' => ['visibility'],
    'importance' => ['importance'],
])->throws(ActivityRecordingException::class);

test('blank event keys never create activity rows', function (): void {
    $activity = app(ActivityRecorder::class)->record(
        subject: null,
        event: ' ',
        description: 'Ignored',
    );

    expect($activity)->toBeNull()
        ->and(ActivityLog::query()->count())->toBe(0);
});
