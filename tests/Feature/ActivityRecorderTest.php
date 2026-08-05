<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Nvl\Activity\Enums\ActivitySource;
use Nvl\Activity\Enums\ActivityVisibility;
use Nvl\Activity\Exceptions\ActivityRecordingException;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Tests\Stubs\TestActivityCauser;
use Nvl\Activity\Tests\Stubs\TestActivityUser;

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
