<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Nvl\Activity\Contracts\ActivityAuthorization;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityPurgeDispatcher;
use Nvl\Activity\Services\ActivityReader;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Support\ActivityPurgeCriteria;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Support\Enums\SystemActor;

test('activity transports belong to the consumer even when stale route config is enabled', function (): void {
    config(['nvl-activity.routes.enabled' => true]);
    $this->getJson('/nvl/api/v1/activity')->assertNotFound();
});

test('native index accepts bounded multi-event filters', function (): void {
    foreach (['created', 'reviewed', 'deleted'] as $event) {
        app(ActivityRecorder::class)->record(['event' => $event], SystemActor::Background);
    }
    expect(app(ActivityReader::class)->paginate(['events' => ['created', 'reviewed']], SystemActor::Background)->total())->toBe(2);
});

test('native index authorization fails closed without a host policy', function (): void {
    $this->app->offsetUnset(ActivityAuthorization::class);
    expect(fn () => app(ActivityReader::class)->paginate([], SystemActor::Background))->toThrow(AuthorizationException::class);
});

test('direct recording rejects null actors before dispatch', function (): void {
    expect(fn () => app(ActivityRecorder::class)->record(['event' => 'reviewed'], null))->toThrow(TypeError::class);
});

test('consumer transport aliases are not accepted by native query contracts', function (): void {
    expect(fn () => app(ActivityReader::class)->paginate(['perPage' => 20], SystemActor::Background))->toThrow(InvalidArgumentException::class);
});

test('timeline requires a host-authorized explicit subject', function (): void {
    $this->app->offsetUnset(ActivityAuthorization::class);
    expect(fn () => app(ActivityReader::class)->timeline(new ActivitySubjectReference('record', 'one'), SystemActor::Background))->toThrow(AuthorizationException::class);
});

test('empty subject histories return an empty native collection', function (): void {
    expect(app(ActivityReader::class)->timeline(new ActivitySubjectReference('record', 'missing'), SystemActor::Background))->toBeEmpty();
});

test('purge dispatch authorizes through the host boundary', function (): void {
    $this->app->offsetUnset(ActivityAuthorization::class);
    Bus::fake();
    expect(fn () => app(ActivityPurgeDispatcher::class)->dispatch(ActivityPurgeCriteria::fromDays(90), SystemActor::Background))->toThrow(AuthorizationException::class);
    Bus::assertNothingDispatched();
});

test('purge retains explicit important-evidence inclusion', function (): void {
    Bus::fake();
    app(ActivityPurgeDispatcher::class)->dispatch(ActivityPurgeCriteria::fromDays(90, false, true), SystemActor::Background);
    Bus::assertDispatched(PurgeActivityLogsJob::class, fn (PurgeActivityLogsJob $job): bool => $job->criteria->includeImportant);
});

test('causer suggestions deny access without a host policy', function (): void {
    $this->app->offsetUnset(ActivityAuthorization::class);
    expect(fn () => app(ActivityReader::class)->causers(SystemActor::Background))->toThrow(AuthorizationException::class);
});

test('native query limits reject invalid types', function (): void {
    expect(fn () => app(ActivityReader::class)->paginate(['per_page' => '20'], SystemActor::Background))->toThrow(InvalidArgumentException::class);
});

test('native validation rejects ownership assignment through an entry', function (): void {
    expect(fn () => app(ActivityRecorder::class)->record(['event' => 'reviewed', 'owner_reference' => 'forged'], SystemActor::Background))->toThrow(InvalidArgumentException::class);
    expect(ActivityLog::query()->count())->toBe(0);
});

test('native validation rejects forged causer metadata', function (): void {
    expect(fn () => app(ActivityRecorder::class)->record(['event' => 'reviewed', 'causer_id' => 'forged'], SystemActor::Background))->toThrow(InvalidArgumentException::class);
});

test('native query dates reject contradictory bounds', function (): void {
    expect(fn () => app(ActivityReader::class)->paginate(['created_at_from' => '2026-08-02', 'created_at_to' => '2026-08-01'], SystemActor::Background))->toThrow(InvalidArgumentException::class);
});

test('native metadata rejects unstable object values', function (): void {
    expect(fn () => app(ActivityRecorder::class)->record(['event' => 'reviewed', 'context' => ['object' => new stdClass]], SystemActor::Background))->toThrow(InvalidArgumentException::class);
});

test('native system records expose canonical facts without response envelopes', function (): void {
    $record = app(ActivityRecorder::class)->record(['event' => 'reviewed'], SystemActor::Background);
    expect($record)->toBeInstanceOf(ActivityLog::class)->and($record->properties->get('source'))->toBe('system');
});
