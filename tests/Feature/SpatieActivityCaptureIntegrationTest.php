<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityCaptureContext;
use Nvl\Activity\Services\MappingRegistry;
use Nvl\Activity\Services\SpatieActivityCaptureAdapter;
use Nvl\Activity\Support\ActivityCaptureOptions;
use Nvl\Activity\Tests\Fixtures\Spatie\DualEngineSubject;
use Nvl\Activity\Tests\Fixtures\Spatie\NativeBridgeSubject;
use Nvl\Activity\Tests\Fixtures\Spatie\ReversedDualEngineSubject;
use Nvl\Activity\Tests\Fixtures\Spatie\SpatieBridgeSubject;
use Nvl\Activity\Tests\Stubs\TestActivityMapping;
use Nvl\Support\Enums\SystemActor;
use Spatie\Activitylog\Models\Activity as SpatieActivity;
use Spatie\Activitylog\Support\LogOptions;

beforeEach(function (): void {
    SpatieBridgeSubject::$logOnlyDirty = true;
    SpatieBridgeSubject::$dontLogEmptyChanges = true;
});

/** Map the native model with the options the adapter derives from the real Spatie options. */
function registerBridgedNativeCapture(): void
{
    $review = app(SpatieActivityCaptureAdapter::class)->review(
        (new SpatieBridgeSubject)->getActivitylogOptions(),
        ['created', 'updated', 'deleted', 'restored'],
    );
    expect($review['unsupported'])->toBe([]);

    app(MappingRegistry::class)->register(new TestActivityMapping(NativeBridgeSubject::class, capture: $review['options']));
}

/**
 * Run the same business steps against one model class.
 *
 * @param  class-string<SpatieBridgeSubject|NativeBridgeSubject>  $class
 * @param  Closure(Model): void  $steps
 */
function runBridgeScenario(string $class, Closure $steps): void
{
    app(ActivityCaptureContext::class)->run(SystemActor::Background, function () use ($class, $steps): void {
        $steps($class::query()->create(['name' => 'before', 'priority' => 1, 'secret' => 'first']));
    });
}

/**
 * Normalize each engine's trail, ignoring ids and timestamps.
 *
 * @return array{spatie:list<array<string,mixed>>,native:list<array<string,mixed>>}
 */
function bridgeTrails(): array
{
    $normalize = static fn (Model $row): array => [
        'event' => $row->getAttribute('event'),
        'log_name' => $row->getAttribute('log_name'),
        'attributes' => $row->getAttribute('attribute_changes')?->get('attributes') ?? [],
        'old' => $row->getAttribute('attribute_changes')?->get('old') ?? [],
    ];

    return [
        'spatie' => SpatieActivity::query()->orderBy('id')->get()->map($normalize)->all(),
        'native' => ActivityLog::query()->oldestFirst()->get()->map($normalize)->all(),
    ];
}

test('equivalent lifecycle diffs from real Spatie v5 and native capture', function (): void {
    registerBridgedNativeCapture();
    $steps = static function (Model $subject): void {
        $subject->update(['name' => 'after', 'secret' => 'second']);
        $subject->delete();
        $subject->restore();
    };

    runBridgeScenario(SpatieBridgeSubject::class, $steps);
    runBridgeScenario(NativeBridgeSubject::class, $steps);
    $trails = bridgeTrails();

    expect($trails['spatie'])->toHaveCount(4)
        ->and(array_column($trails['spatie'], 'event'))->toBe(['created', 'updated', 'deleted', 'restored'])
        ->and(array_unique(array_column($trails['spatie'], 'log_name')))->toBe(['bridge'])
        ->and($trails['native'])->toBe($trails['spatie']);
});

test('dirty, empty and excluded fields match Spatie for both flags', function (bool $onlyDirty, bool $suppressEmpty, Closure $change): void {
    SpatieBridgeSubject::$logOnlyDirty = $onlyDirty;
    SpatieBridgeSubject::$dontLogEmptyChanges = $suppressEmpty;
    registerBridgedNativeCapture();

    runBridgeScenario(SpatieBridgeSubject::class, $change);
    runBridgeScenario(NativeBridgeSubject::class, $change);
    $trails = bridgeTrails();

    expect($trails['native'])->toBe($trails['spatie'])
        ->and(json_encode($trails['native']))->not->toContain('secret');
})->with([
    'dirty, suppress empty' => [true, true],
    'dirty, keep empty' => [true, false],
    'all fields, suppress empty' => [false, true],
    'all fields, keep empty' => [false, false],
])->with([
    'untracked change' => [static fn (Model $subject) => $subject->update(['other' => 'untracked', 'secret' => 'hidden'])],
    'unchanged save' => [static fn (Model $subject) => $subject->update(['name' => 'before'])],
    'null transition' => [static fn (Model $subject) => $subject->update(['name' => null])],
    'cast attribute' => [static fn (Model $subject) => $subject->update(['priority' => '7'])],
]);

test('unsupported real v5 options are reported', function (LogOptions $options, string $unsupported): void {
    expect(app(SpatieActivityCaptureAdapter::class)->review($options, ['updated']))
        ->toBe(['options' => null, 'unsupported' => [$unsupported]]);
})->with([
    'wildcard' => [fn () => LogOptions::defaults()->logAll(), 'logAttributes'],
    'fillable' => [fn () => LogOptions::defaults()->logOnly(['name'])->logFillable(), 'logFillable'],
    'closure description' => [fn () => LogOptions::defaults()->logOnly(['name'])->setDescriptionForEvent(fn (string $event): string => $event), 'descriptionForEvent'],
    'raw values' => [fn () => LogOptions::defaults()->logOnly(['name'])->useAttributeRawValues(['name']), 'attributeRawValues'],
]);

test('a model using both engines fails at boot', function (string $class, ?ActivityCaptureOptions $capture): void {
    if ($capture !== null) {
        app(MappingRegistry::class)->register(new TestActivityMapping($class, capture: $capture));
    }

    $create = fn () => app(ActivityCaptureContext::class)->run(SystemActor::Background, fn () => $class::query()->create(['name' => 'never']));

    expect($create)->toThrow(LogicException::class, 'Remove the Spatie logging trait before enabling native capture on this model.')
        ->and($create)->toThrow(LogicException::class, 'Remove the Spatie logging trait before enabling native capture on this model.');

    expect(DB::table('native_bridge_subjects')->count())->toBe(0)
        ->and(ActivityLog::query()->count())->toBe(0)
        ->and(SpatieActivity::query()->count())->toBe(0);
})->with([
    'native trait first' => [DualEngineSubject::class],
    'Spatie trait first' => [ReversedDualEngineSubject::class],
])->with([
    'mapping absent' => [null],
    'mapping disabled' => [fn () => new ActivityCaptureOptions(['name'], events: [])],
]);
