<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityCaptureContext;
use Nvl\Activity\Services\ActivityReader;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Services\MappingRegistry;
use Nvl\Activity\Support\ActivityRecordEnvelope;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Activity\Tests\Fixtures\CustomActivityLog;
use Nvl\Activity\Tests\Stubs\TestActivityMapping;
use Nvl\Activity\Tests\Stubs\TestActivitySubjectWithHasModelActivity;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use RuntimeException;

trait ConfiguresActivityModelEnvironment
{
    /** @var array<string, class-string<Model>>|null */
    protected ?array $previousMorphMap = null;

    protected function restoreConfiguredMorphMap(): void
    {
        Relation::morphMap($this->previousMorphMap ?? [], false);
    }

    /** @var class-string */
    protected string $activityModel = CustomActivityLog::class;

    protected function defineEnvironment($app): void
    {
        $this->previousMorphMap ??= Relation::morphMap();
        Relation::morphMap([], false);
        $app['config']->set('nvl-activity.models.activity_log', $this->activityModel);
        $app['config']->set('nvl-activity.adoption.spatie', true);
    }
}

uses(ConfiguresActivityModelEnvironment::class);

afterEach(function (): void {
    $this->restoreConfiguredMorphMap();
});

it('native recording reading factory and morph identity use the selected model', function (): void {
    $log = app(ActivityRecorder::class)->record(['event' => 'approved'], SystemActor::Background);
    $this->assertSame(CustomActivityLog::class, $log::class);
    $this->assertSame(CustomActivityLog::class, app(ActivityReader::class)->paginate([], SystemActor::Background)->first()::class);
    $this->assertSame(CustomActivityLog::class, ActivityLog::factory()->create()::class);
    $this->assertSame(CustomActivityLog::class, Relation::getMorphedModel($log->getMorphClass()));
    $this->assertSame(CustomActivityLog::class, config('activitylog.activity_model'));
});

it('native recording uses the selected models outer transaction', function (): void {
    $log = app(ActivityRecorder::class)->record(['event' => 'before'], SystemActor::Background);
    try {
        $log->getConnection()->transaction(function (): void {
            app(ActivityRecorder::class)->record(['event' => 'rolled_back'], SystemActor::Background);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException $exception) {
        $this->assertSame('rollback', $exception->getMessage());
    }
    $this->assertSame(1, app(ActivityReader::class)->paginate([], SystemActor::Background)->total());
});

it('automatic capture and subject relations return the selected root', function (): void {
    Schema::create('activity_mapping_subjects', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    app(MappingRegistry::class)->register(new TestActivityMapping(TestActivitySubjectWithHasModelActivity::class));
    $subject = app(ActivityCaptureContext::class)->run(SystemActor::Background, fn () => TestActivitySubjectWithHasModelActivity::create(['name' => 'Captured']));
    $this->assertSame(CustomActivityLog::class, $subject->activitiesAsSubject()->first()::class);
    $this->assertSame(CustomActivityLog::class, ActivityLog::forDisplay()->first()::class);
    $this->assertSame($subject->getKey(), $subject->activitiesAsSubject()->first()->subject->getKey());
});

it('immutable envelope delivery replays through the selected root', function (): void {
    $envelope = new ActivityRecordEnvelope((string) Str::uuid(), new ActivitySubjectReference('historical-subject', '7'), null, 'delivered', 'audit', CarbonImmutable::now());
    $recorder = app(ActivityRecorder::class);
    $first = $recorder->recordEnvelope($envelope);
    $replay = $recorder->recordEnvelope($envelope);
    $this->assertSame(CustomActivityLog::class, $first::class);
    $this->assertSame(CustomActivityLog::class, $replay::class);
    $this->assertSame($first->id, $replay->id);
    $this->assertSame(1, app(ActivityReader::class)->paginate([], SystemActor::Background)->total());
});

it('replacement cannot change package storage', function (): void {
    $this->activityModel = ForeignActivityStorage::class;
    $this->expectException(OwnershipConfigurationInvalid::class);
    $this->expectExceptionMessage('package-configured table and connection');
    $morphMap = Relation::morphMap();
    Relation::morphMap([], false);
    try {
        $this->refreshApplication();
    } finally {
        Relation::morphMap($morphMap, false);
    }
});

it('replacement cannot change native query hooks', function (): void {
    $this->activityModel = UnsafeActivityQuery::class;
    $this->expectException(OwnershipConfigurationInvalid::class);
    $this->expectExceptionMessage('canonical query and persistence hooks');
    $morphMap = Relation::morphMap();
    Relation::morphMap([], false);
    try {
        $this->refreshApplication();
    } finally {
        Relation::morphMap($morphMap, false);
    }
});

final class ForeignActivityStorage extends ActivityLog
{
    public function getTable(): string
    {
        return 'foreign_activity';
    }
}

final class UnsafeActivityQuery extends ActivityLog
{
    public function newQuery()
    {
        return parent::newQuery();
    }
}
