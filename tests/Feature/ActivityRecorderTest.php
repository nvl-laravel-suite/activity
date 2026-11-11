<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Activity\Enums\ActivityEvent;
use Nvl\Activity\Enums\ActivitySource;
use Nvl\Activity\Exceptions\ActivityRecordingException;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Ownership\ActivityOwnershipGuard;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Support\ActivityCauserReference;
use Nvl\Activity\Support\ActivityRecordEnvelope;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Activity\Tests\Stubs\TestActivityCauser;
use Nvl\Activity\Tests\Stubs\TestActivityUser;
use Nvl\Support\Enums\SystemActor;

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
    $activity = app(ActivityRecorder::class)->recordEnvelope($restored);
    $replayed = app(ActivityRecorder::class)->recordEnvelope($restored);

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
        ->and($activity->attribute_changes->get('attributes'))->toBe(['title' => 'After'])
        ->and($activity->attribute_changes->get('old'))->toBe(['title' => 'Before'])
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

    app(ActivityRecorder::class)->recordEnvelope(ActivityRecordEnvelope::fromArray($payload));
    $payload['context'] = ['state' => 'closed'];

    expect(fn () => app(ActivityRecorder::class)->recordEnvelope(ActivityRecordEnvelope::fromArray($payload)))
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
    $activity = app(ActivityRecorder::class)->recordEnvelope($envelope);
    $activity->properties = $activity->properties->put('context', ['state' => 'closed']);
    DB::connection($activity->getConnectionName())->table($activity->getTable())->where('id', $activity->id)->update($activity->getDirty());

    expect(fn () => app(ActivityRecorder::class)->recordEnvelope($envelope))->toThrow(ActivityRecordingException::class);
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
    $activity = app(ActivityRecorder::class)->recordEnvelope($envelope);
    $activity->created_at = CarbonImmutable::parse('2026-09-29T10:11:12.000000+00:00');
    DB::connection($activity->getConnectionName())->table($activity->getTable())->where('id', $activity->id)->update($activity->getDirty());

    expect(fn () => app(ActivityRecorder::class)->recordEnvelope($envelope))->toThrow(ActivityRecordingException::class);
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
    app(ActivityRecorder::class)->recordEnvelope(ActivityRecordEnvelope::fromArray($payload));
    $payload['context'] = ['after' => 'closed', 'before' => 'open'];

    expect(app(ActivityRecorder::class)->recordEnvelope(ActivityRecordEnvelope::fromArray($payload))->getKey())->toBe($payload['id'])
        ->and(ActivityLog::query()->count())->toBe(1);
});

test('the canonical writer records caller batches and rejects scalar attribution overrides', function (): void {
    $batch = (string) Str::uuid();
    $activity = app(ActivityRecorder::class)->record(['event' => 'reviewed', 'context' => ['reason' => 'manual review'], 'batch_uuid' => $batch], SystemActor::Background);
    expect($activity->batch_uuid)->toBe($batch)->and($activity->properties->get('context'))->toBe(['reason' => 'manual review']);
    expect(fn () => app(ActivityRecorder::class)->record(['event' => 'reviewed', 'actor_id' => 'forged'], SystemActor::Background))->toThrow(InvalidArgumentException::class);
});

test('the canonical writer records model-free subject references without reading a subject table', function (): void {
    $subject = new ActivitySubjectReference(' domain.resource ', ' resource-42 ');
    app(ActivityOwnershipGuard::class)->attributes();
    DB::flushQueryLog();
    DB::enableQueryLog();

    $activity = app(ActivityRecorder::class)->record(['subject' => $subject, 'event' => 'consumer.reference_recorded', 'context' => ['reason' => 'external subject'], 'importance' => 'important'], SystemActor::Background);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($activity)->toBeInstanceOf(ActivityLog::class)
        ->and($activity?->subject_type)->toBe('domain.resource')
        ->and($activity?->subject_id)->toBe('resource-42')
        ->and($activity?->properties?->get('context'))->toBe(['reason' => 'external subject'])
        ->and($activity?->properties?->get('source'))->toBe('system')
        ->and($activity?->properties?->get('importance'))->toBe('important')
        ->and(array_values(array_filter($queries, static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'insert'))))->toHaveCount(1)
        ->and(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'domain.resource')))->toBe([]);
});

test('the canonical writer accepts package owned activity events', function (): void {
    $activity = app(ActivityRecorder::class)->record(['subject' => null, 'event' => ActivityEvent::Triggered], SystemActor::Background);

    expect($activity?->event)->toBe(ActivityEvent::Triggered->value)
        ->and($activity?->description)->toBe(ActivityEvent::Triggered->value);
});

test('package owned update events infer saved model changes without a description', function (): void {
    Schema::create('mutable_activity_subjects', fn (Blueprint $table) => [$table->id(), $table->string('description')]);
    $subject = new class extends Model
    {
        protected $table = 'mutable_activity_subjects';

        protected $guarded = [];

        public $timestamps = false;
    };
    $subject->fill(['description' => 'Before'])->save();
    $subject->timestamps = false;
    $subject->forceFill(['description' => 'After'])->save();

    $activity = app(ActivityRecorder::class)->record(['subject' => $subject, 'event' => ActivityEvent::Updated], SystemActor::Background);

    expect($activity?->event)->toBe(ActivityEvent::Updated->value)
        ->and($activity?->description)->toBe(ActivityEvent::Updated->value)
        ->and($activity?->attribute_changes?->get('attributes'))->toBe(['description' => 'After'])
        ->and($activity?->attribute_changes?->get('old'))->toBe(['description' => 'Before']);
});

test('the canonical writer normalizes caller supplied log names and descriptions', function (): void {
    $activity = app(ActivityRecorder::class)->record(['subject' => null, 'event' => 'consumer.semantic_event', 'description' => '  Consumer semantic event  ', 'log_name' => '  consumer  '], SystemActor::Background);

    expect($activity?->log_name)->toBe('consumer')
        ->and($activity?->description)->toBe('Consumer semantic event');
});

test('model actors use the native polymorphic causer relation', function (): void {
    Schema::create('activity_test_causers', function (Blueprint $table): void {
        $table->increments('causer_key');
        $table->string('name');
    });
    $actor = TestActivityCauser::query()->create(['name' => 'Operator']);
    $activity = app(ActivityRecorder::class)->record(['event' => 'reviewed'], $actor);
    expect($activity->causer_type)->toBe($actor->getMorphClass())->and($activity->causer_id)->toBe((string) $actor->getKey())->and($activity->properties->get('source'))->toBe('user');
});

test('anonymous and blank scalar actors cannot grant system authority', function (?string $actor): void {
    $ambientActor = new TestActivityUser;
    $ambientActor->forceFill(['id' => 99]);
    $this->actingAs($ambientActor);

    expect(fn () => app(ActivityRecorder::class)->record(['event' => 'reviewed'], $actor))->toThrow(TypeError::class);
    expect(ActivityLog::query()->count())->toBe(0);
})->with([
    'null actor' => null,
    'blank actor' => '',
    'whitespace actor' => '   ',
]);

test('ambient authentication never changes system purge classification', function (): void {
    $ambientActor = new TestActivityUser;
    $ambientActor->forceFill(['id' => 99]);
    $this->actingAs($ambientActor);

    $activity = app(ActivityRecorder::class)->record(['subject' => null, 'event' => 'synchronized', 'description' => 'System synchronization'], SystemActor::Background);
    $activity?->forceFill([
        'created_at' => now()->subDays(120),
        'updated_at' => now()->subDays(120),
    ]);
    DB::connection($activity->getConnectionName())->table($activity->getTable())->where('id', $activity->id)->update($activity->getDirty());

    expect(PurgeActivityLogsJob::countPurgeable(90, systemOnly: true))->toBe(1)
        ->and($activity?->causer_id)->toBeNull()
        ->and($activity?->properties?->get('source'))->toBe(ActivitySource::System->value);
});

test('source attribution cannot be overridden and blank visibility is rejected', function (): void {
    expect(fn () => app(ActivityRecorder::class)->record(['event' => 'reviewed', 'source' => 'system'], SystemActor::Background))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(ActivityRecorder::class)->record(['event' => 'reviewed', 'visibility' => ' '], SystemActor::Background))->toThrow(InvalidArgumentException::class);
});

test('caller owned activity batch identifiers must be valid uuids', function (): void {
    app(ActivityRecorder::class)->record(['subject' => null, 'event' => 'synchronized', 'description' => 'Synchronized', 'batch_uuid' => 'not-a-uuid'], SystemActor::Background);
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

    expect(fn () => app(ActivityRecorder::class)->record($arguments, SystemActor::Background))->toThrow(InvalidArgumentException::class);
})->with([
    'source' => ['source'],
    'visibility' => ['visibility'],
    'importance' => ['importance'],
]);

test('blank event keys never create activity rows', function (): void {
    expect(fn () => app(ActivityRecorder::class)->record(['event' => ' '], SystemActor::Background))->toThrow(InvalidArgumentException::class);
    expect(ActivityLog::query()->count())->toBe(0);
});

test('descriptions are bounded by the bytes a TEXT column stores', function (): void {
    $recorder = app(ActivityRecorder::class);

    expect($recorder->record(['event' => 'reviewed', 'description' => str_repeat('я', 32767)], SystemActor::Background)->description)->toHaveLength(32767)
        ->and(fn () => $recorder->record(['event' => 'reviewed', 'description' => str_repeat('я', 32768)], SystemActor::Background))
        ->toThrow(InvalidArgumentException::class);
});
