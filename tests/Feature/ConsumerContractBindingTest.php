<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Nvl\Activity\Actions\Activity\ListActivitiesAction;
use Nvl\Activity\Actions\Activity\ListActivityCauserSuggestionsAction;
use Nvl\Activity\Actions\Activity\QueueActivityLogPurgeAction;
use Nvl\Activity\Contracts\ActivityReadContract;
use Nvl\Activity\Contracts\ActivityRecorderContract;
use Nvl\Activity\Contracts\ListActivitiesContract;
use Nvl\Activity\Contracts\ListActivityCauserSuggestionsContract;
use Nvl\Activity\Contracts\QueueActivityLogPurgeContract;
use Nvl\Activity\Data\ActivityIndexFilter;
use Nvl\Activity\Data\Display\ActivityCauserSuggestion;
use Nvl\Activity\Data\Display\ActivityItem;
use Nvl\Activity\Events\ActivityLogPurgeQueued;
use Nvl\Activity\Facades\ActivityLog as ActivityLogFacade;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Activity\Services\ActivityReadService;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Services\ActivityTransformService;
use Nvl\Activity\Services\NvlActivityLogger;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Activity\Support\ModelKeyIdentifierValidator;
use Nvl\Activity\Tenancy\ActivityOwnershipGuard;
use Nvl\Activity\Tests\Fixtures\ActivityConsumerWorkflow;
use Nvl\Activity\Tests\TestCase;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Spatie\LaravelData\DataCollection;

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses(TestCase::class);
}

dataset('activity consumer contracts', [
    'activity listing' => [ListActivitiesContract::class, ListActivitiesAction::class, false],
    'causer suggestions' => [ListActivityCauserSuggestionsContract::class, ListActivityCauserSuggestionsAction::class, false],
    'queued purge' => [QueueActivityLogPurgeContract::class, QueueActivityLogPurgeAction::class, true],
    'activity reads' => [ActivityReadContract::class, ActivityReadService::class, false],
    'activity recording' => [ActivityRecorderContract::class, ActivityRecorder::class, false],
]);

test('consumer interfaces preserve the complete native instance API and its generic documentation', function (string $contract, string $implementation, bool $readonly): void {
    expect(interface_exists($contract))->toBeTrue();

    $interface = new ReflectionClass($contract);
    $concrete = new ReflectionClass($implementation);
    $methods = array_values(array_filter($concrete->getMethods(ReflectionMethod::IS_PUBLIC), static fn (ReflectionMethod $method): bool => ! $method->isConstructor() && ! $method->isStatic()));

    expect($concrete->implementsInterface($contract))->toBeTrue()
        ->and($concrete->isFinal())->toBeTrue()
        ->and($concrete->isReadOnly())->toBe($readonly)
        ->and(array_map(static fn (ReflectionMethod $method): string => $method->getName(), $interface->getMethods()))
        ->toBe(array_map(static fn (ReflectionMethod $method): string => $method->getName(), $methods));

    foreach ($methods as $method) {
        $declared = $interface->getMethod($method->getName());

        expect((string) $declared->getReturnType())->toBe((string) $method->getReturnType())
            ->and($declared->getDocComment())->toBe($method->getDocComment())
            ->and(count($declared->getParameters()))->toBe(count($method->getParameters()));

        foreach ($method->getParameters() as $position => $parameter) {
            $interfaceParameter = $declared->getParameters()[$position];

            expect($interfaceParameter->getName())->toBe($parameter->getName())
                ->and((string) $interfaceParameter->getType())->toBe((string) $parameter->getType())
                ->and($interfaceParameter->isPassedByReference())->toBe($parameter->isPassedByReference())
                ->and($interfaceParameter->isVariadic())->toBe($parameter->isVariadic())
                ->and($interfaceParameter->isDefaultValueAvailable())->toBe($parameter->isDefaultValueAvailable())
                ->and(array_map(static fn (ReflectionAttribute $attribute): array => [$attribute->getName(), $attribute->getArguments()], $interfaceParameter->getAttributes()))
                ->toBe(array_map(static fn (ReflectionAttribute $attribute): array => [$attribute->getName(), $attribute->getArguments()], $parameter->getAttributes()));

            if ($parameter->isDefaultValueAvailable()) {
                expect($interfaceParameter->getDefaultValue())->toEqual($parameter->getDefaultValue());
            }
        }
    }
})->with('activity consumer contracts');

test('default consumer bindings resolve the existing concrete implementations', function (string $contract, string $implementation): void {
    expect($this->app->bound($contract))->toBeTrue()
        ->and($this->app->make($contract))->toBeInstanceOf($implementation)
        ->and($this->app->make($implementation))->toBeInstanceOf($implementation);
})->with('activity consumer contracts');

test('provider discovery preserves host interface instances', function (string $contract): void {
    expect(interface_exists($contract))->toBeTrue();

    $host = Mockery::mock($contract);
    $consumer = new Application($this->app->basePath());
    $consumer->instance('config', new Repository($this->app->make('config')->all()));
    $consumer->instance('env', 'testing');
    $consumer->register(FilesystemServiceProvider::class);
    $consumer->instance($contract, $host);

    try {
        $consumer->register(ActivityServiceProvider::class);

        expect($consumer->make($contract))->toBe($host);
    } finally {
        Container::setInstance($this->app);
        $consumer->flush();
    }
})->with('activity consumer contracts');

test('late interface replacement reaches newly resolved host services', function (string $contract, string $implementation): void {
    expect($this->app->bound($contract))->toBeTrue()
        ->and(interface_exists(ListActivitiesContract::class))->toBeTrue();
    expect($this->app->make($contract))->toBeInstanceOf($implementation);

    $host = Mockery::mock($contract);
    $this->app->instance($contract, $host);
    $workflow = $this->app->make(ActivityConsumerWorkflow::class);

    expect($this->app->make($contract))->toBe($host)
        ->and($workflow->dependency($contract))->toBe($host);
})->with('activity consumer contracts');

test('concrete constructors retain their original typed dependencies and defaults', function (): void {
    $expected = [
        ListActivitiesAction::class => [
            ['activityReadService', ActivityReadService::class, false, null],
            ['activityTransformService', ActivityTransformService::class, false, null],
        ],
        ListActivityCauserSuggestionsAction::class => [
            ['modelKeyIdentifierValidator', ModelKeyIdentifierValidator::class, true, ModelKeyIdentifierValidator::class],
            ['ownership', '?'.ActivityOwnershipGuard::class, true, null],
        ],
        QueueActivityLogPurgeAction::class => [['tenantContext', TenantContext::class, false, null], ['domainEvents', '?'.DomainEventDispatcher::class, true, null]],
        ActivityReadService::class => [['ownership', ActivityOwnershipGuard::class, false, null]],
        ActivityRecorder::class => [
            ['ownership', ActivityOwnershipGuard::class, false, null],
            ['logger', NvlActivityLogger::class, false, null],
        ],
    ];

    foreach ($expected as $implementation => $parameters) {
        $constructor = (new ReflectionClass($implementation))->getConstructor()
            ?? throw new LogicException('The concrete workflow must retain its constructor.');
        $actual = array_map(static function (ReflectionParameter $parameter): array {
            $default = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;

            return [$parameter->getName(), (string) $parameter->getType(), $parameter->isDefaultValueAvailable(), is_object($default) ? $default::class : $default];
        }, $constructor->getParameters());

        expect($actual)->toBe($parameters);
    }
});

test('provider discovery retains preconstructed public scoped concrete instances', function (string $implementation, string $contract): void {
    $ownership = $this->app->make(ActivityOwnershipGuard::class);
    $host = $implementation === ActivityReadService::class
        ? new ActivityReadService($ownership)
        : new ActivityRecorder($ownership, $this->app->make(NvlActivityLogger::class));
    $consumer = new Application($this->app->basePath());
    $consumer->instance('config', new Repository($this->app->make('config')->all()));
    $consumer->instance('env', 'testing');
    $consumer->register(FilesystemServiceProvider::class);
    $consumer->instance($implementation, $host);

    try {
        $consumer->register(ActivityServiceProvider::class);

        expect($consumer->resolved($implementation))->toBeTrue()
            ->and($consumer->make($implementation))->toBe($host)
            ->and($consumer->make($contract))->toBe($host);
    } finally {
        Container::setInstance($this->app);
        $consumer->flush();
    }
})->with([
    'reader' => [ActivityReadService::class, ActivityReadContract::class],
    'recorder' => [ActivityRecorder::class, ActivityRecorderContract::class],
]);

test('a real host workflow consumes generic and void substitutes without resolving package work or querying', function (): void {
    expect(interface_exists(ListActivitiesContract::class))->toBeTrue()
        ->and(interface_exists(ActivityRecorderContract::class))->toBeTrue();

    $filters = new ActivityIndexFilter(null, null, null, null, null);
    $item = new ActivityItem(id: 'activity-1', log: 'host', event: 'reviewed');
    $page = new LengthAwarePaginator([$item], 1, 20);
    $suggestion = new ActivityCauserSuggestion('actor-1', 'Ada');
    $suggestions = new DataCollection(ActivityCauserSuggestion::class, [$suggestion]);
    $reference = new ActivitySubjectReference('host-record', 'record-1');
    $handle = new ActivityLog;
    $handle->setRawAttributes(['id' => 'activity-1']);
    $history = new EloquentCollection([$handle]);

    $listing = Mockery::mock(ListActivitiesContract::class);
    $listing->shouldReceive('execute')->once()->with($filters)->andReturn($page);
    $causers = Mockery::mock(ListActivityCauserSuggestionsContract::class);
    $causers->shouldReceive('execute')->once()->with('Ada', 3)->andReturn($suggestions);
    $reader = Mockery::mock(ActivityReadContract::class);
    $reader->shouldReceive('forSubjectKey')->once()->with('host-record', 'record-1', 5)->andReturn($history);
    $recorder = Mockery::mock(ActivityRecorderContract::class);
    $recorder->shouldReceive('recordForSubjectReference')->once()->with($reference, 'reviewed')->andReturn($handle);
    $purge = Mockery::mock(QueueActivityLogPurgeContract::class);
    $purge->shouldReceive('execute')->once()->with(365, true, false)->andReturnNull();

    foreach ([
        ListActivitiesContract::class => $listing,
        ListActivityCauserSuggestionsContract::class => $causers,
        ActivityReadContract::class => $reader,
        ActivityRecorderContract::class => $recorder,
        QueueActivityLogPurgeContract::class => $purge,
    ] as $contract => $substitute) {
        $this->app->instance($contract, $substitute);
    }
    foreach ([ListActivitiesAction::class, ListActivityCauserSuggestionsAction::class, QueueActivityLogPurgeAction::class, ActivityReadService::class, ActivityRecorder::class, ActivityOwnershipGuard::class, ActivityTransformService::class, NvlActivityLogger::class] as $implementation) {
        $this->app->bind($implementation, static fn (): never => throw new LogicException('Real package work must not resolve: '.$implementation));
    }

    Queue::fake();
    Event::fake();
    $connection = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    $workflow = $this->app->make(ActivityConsumerWorkflow::class);

    expect($workflow->index($filters)->items())->toBe([$item])
        ->and($workflow->suggestions('Ada', 3)->all())->toBe([$suggestion])
        ->and($workflow->history($reference)->first())->toBe($handle)
        ->and($workflow->record($reference))->toBe($handle)
        ->and($workflow->purgeSystemHistory())->toBe('queued')
        ->and($connection->getQueryLog())->toBe([]);
    Queue::assertNothingPushed();
    Event::assertNothingDispatched();
});

test('the recording facade reaches a late contract substitute after an earlier real resolution', function (): void {
    expect(interface_exists(ActivityRecorderContract::class))->toBeTrue();
    $original = ActivityLogFacade::getFacadeRoot();
    expect($original)->toBeInstanceOf(ActivityRecorder::class);

    $handle = new ActivityLog;
    $handle->setRawAttributes(['id' => 'facade-activity']);
    $recorder = Mockery::mock(ActivityRecorderContract::class);
    $recorder->shouldReceive('record')->once()->with(null, 'reviewed')->andReturn($handle);
    $this->app->instance(ActivityRecorderContract::class, $recorder);
    $this->app->bind(ActivityRecorder::class, static fn (): never => throw new LogicException('The concrete recorder must not resolve.'));
    ActivityLogFacade::clearResolvedInstance(ActivityRecorderContract::class);

    expect(ActivityLogFacade::getFacadeRoot())->toBe($recorder)
        ->and($this->app->make(ActivityRecorderContract::class))->toBe($recorder)
        ->and(ActivityLogFacade::record(null, 'reviewed'))->toBe($handle);
});

test('public reader and recorder defaults share concrete scope and discard late substitutes on a new lifecycle', function (string $contract, string $implementation): void {
    expect($this->app->bound($contract))->toBeTrue();
    $original = $this->app->make($contract);

    expect($original)->toBe($this->app->make($implementation))
        ->and($this->app->make($contract))->toBe($original);

    $substitute = Mockery::mock($contract);
    $this->app->instance($contract, $substitute);
    expect($this->app->make($contract))->toBe($substitute);

    $this->app->forgetScopedInstances();
    ActivityLogFacade::clearResolvedInstance(ActivityRecorderContract::class);
    $next = $this->app->make($contract);

    expect($next)->toBeInstanceOf($implementation)
        ->not->toBe($original)
        ->not->toBe($substitute)
        ->and($next)->toBe($this->app->make($implementation));
})->with([
    'reader' => [ActivityReadContract::class, ActivityReadService::class],
    'recorder' => [ActivityRecorderContract::class, ActivityRecorder::class],
]);

test('two application lifecycles keep scoped defaults and facade substitutes independent', function (): void {
    expect($this->app->bound(ActivityReadContract::class))->toBeTrue();
    $firstReader = $this->app->make(ActivityReadContract::class);
    $firstRecorder = $this->app->make(ActivityRecorderContract::class);
    $reader = Mockery::mock(ActivityReadContract::class);
    $recorder = Mockery::mock(ActivityRecorderContract::class);
    $this->app->instance(ActivityReadContract::class, $reader);
    $this->app->instance(ActivityRecorderContract::class, $recorder);
    ActivityLogFacade::clearResolvedInstance(ActivityRecorderContract::class);
    expect(ActivityLogFacade::getFacadeRoot())->toBe($recorder);

    $second = $this->createApplication();

    try {
        ActivityLogFacade::clearResolvedInstance(ActivityRecorderContract::class);
        $secondReader = $second->make(ActivityReadContract::class);
        $secondRecorder = $second->make(ActivityRecorderContract::class);

        expect($secondReader)->toBeInstanceOf(ActivityReadService::class)
            ->not->toBe($firstReader)
            ->not->toBe($reader)
            ->and($secondRecorder)->toBeInstanceOf(ActivityRecorder::class)
            ->not->toBe($firstRecorder)
            ->not->toBe($recorder)
            ->and(ActivityLogFacade::getFacadeRoot())->toBe($secondRecorder)
            ->and($this->app->make(ActivityReadContract::class))->toBe($reader)
            ->and($this->app->make(ActivityRecorderContract::class))->toBe($recorder);
    } finally {
        Container::setInstance($this->app);
        Facade::setFacadeApplication($this->app);
        Facade::clearResolvedInstances();
        $second->flush();
    }

    expect(ActivityLogFacade::getFacadeRoot())->toBe($recorder);
});

test('historical manual purge construction retains native queueing and canonical committed events', function (): void {
    Queue::fake();
    Event::fake([ActivityLogPurgeQueued::class]);
    $action = new QueueActivityLogPurgeAction(app(TenantContext::class));
    $action->execute(90);
    Queue::assertPushed(PurgeActivityLogsJob::class);
    Event::assertDispatched(ActivityLogPurgeQueued::class, static fn ($event): bool => $event->days === 90);
});
