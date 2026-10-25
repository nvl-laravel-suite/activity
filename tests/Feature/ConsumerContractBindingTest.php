<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Activity\Contracts\ActivityAuthorization;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Activity\Services\ActivityCaptureContext;
use Nvl\Activity\Services\ActivityReader;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Activity\Tests\Fixtures\ActivityConsumerWorkflow;
use Nvl\Activity\Tests\Fixtures\AllowsActivity;
use Nvl\Support\Enums\SystemActor;

test('consumer authorization is the supported replaceable host contract', function (): void {
    $method = new ReflectionMethod(ActivityAuthorization::class, 'authorize');
    expect($method->getNumberOfRequiredParameters())->toBe(2)->and((string) $method->getReturnType())->toBe('void');
});

test('default native services resolve without presentation services', function (): void {
    expect(app(ActivityRecorder::class))->toBeInstanceOf(ActivityRecorder::class)->and(app(ActivityReader::class))->toBeInstanceOf(ActivityReader::class);
});

test('provider discovery preserves a host authorization instance', function (): void {
    $policy = new AllowsActivity;
    app()->instance(ActivityAuthorization::class, $policy);
    (new ActivityServiceProvider(app()))->register();
    expect(app(ActivityAuthorization::class))->toBe($policy);
});

test('late host policy replacement reaches the next scoped service', function (): void {
    app(ActivityRecorder::class);
    app()->forgetScopedInstances();
    app()->offsetUnset(ActivityAuthorization::class);
    expect(fn () => app(ActivityRecorder::class)->record(['event' => 'reviewed'], SystemActor::Background))->toThrow(AuthorizationException::class);
});

test('recorder has explicit domain collaborators', function (): void {
    $constructor = new ReflectionMethod(ActivityRecorder::class, '__construct');
    expect(array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), $constructor->getParameters()))->toBe(['ownership', 'access']);
});

test('provider discovery preserves a preconstructed recorder', function (): void {
    $recorder = app(ActivityRecorder::class);
    app()->instance(ActivityRecorder::class, $recorder);
    (new ActivityServiceProvider(app()))->register();
    expect(app(ActivityRecorder::class))->toBe($recorder);
});

test('host policy sees the explicit background principal and subject', function (): void {
    $policy = new class implements ActivityAuthorization
    {
        public array $calls = [];

        public function authorize(Authenticatable|SystemActor $actor, string $ability, Model|ActivitySubjectReference|null $subject = null): void
        {
            $this->calls[] = [$actor, $ability, $subject];
        }
    };
    app()->instance(ActivityAuthorization::class, $policy);
    $subject = new ActivitySubjectReference('record', 'one');
    app(ActivityRecorder::class)->record(['event' => 'reviewed', 'subject' => $subject], SystemActor::Background);
    expect($policy->calls)->toBe([[SystemActor::Background, 'record', $subject]]);
});

test('host denial prevents direct recording', function (): void {
    app()->instance(ActivityAuthorization::class, new class implements ActivityAuthorization
    {
        public function authorize(Authenticatable|SystemActor $actor, string $ability, Model|ActivitySubjectReference|null $subject = null): void
        {
            throw new AuthorizationException('denied');
        }
    });
    expect(fn () => app(ActivityRecorder::class)->record(['event' => 'reviewed'], SystemActor::Background))->toThrow(AuthorizationException::class, 'denied');
});

test('native reader and recorder are scoped to the application lifecycle', function (): void {
    $reader = app(ActivityReader::class);
    $recorder = app(ActivityRecorder::class);
    expect(app(ActivityReader::class))->toBe($reader)->and(app(ActivityRecorder::class))->toBe($recorder);
    app()->forgetScopedInstances();
    expect(app(ActivityReader::class))->not->toBe($reader)->and(app(ActivityRecorder::class))->not->toBe($recorder);
});

test('capture scopes are restored after nested host failures', function (): void {
    $context = app(ActivityCaptureContext::class);
    $context->run(SystemActor::Background, function () use ($context): void {
        try {
            $context->run(SystemActor::Background, fn () => throw new RuntimeException('failed'));
        } catch (RuntimeException) {
        }
        expect($context->actor())->toBe(SystemActor::Background);
    });
    expect(fn () => $context->actor())->toThrow(AuthorizationException::class);
});

test('native read and capture scopes remain independent across application reset', function (): void {
    $capture = app(ActivityCaptureContext::class);
    $reader = app(ActivityReader::class);
    app()->forgetScopedInstances();
    expect(app(ActivityCaptureContext::class))->not->toBe($capture)->and(app(ActivityReader::class))->not->toBe($reader);
});

test('consumer owned orchestration composes native operations through constructor injection', function (): void {
    $workflow = app(ActivityConsumerWorkflow::class);
    $subject = new ActivitySubjectReference('deleted.record', 'one');
    $record = $workflow->record($subject, SystemActor::Background);
    $page = $workflow->history($subject, SystemActor::Background);
    expect($page->total())->toBe(1)->and($page->items()[0]->id)->toBe($record->id);
});
