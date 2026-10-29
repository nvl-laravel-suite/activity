<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Activity\Services\ActivityCaptureContext;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Services\MappingRegistry;
use Nvl\Activity\Tests\Stubs\HostActivity;
use Nvl\Activity\Tests\Stubs\TestActivityMapping;
use Nvl\Activity\Tests\Stubs\TestActivitySubjectWithHasModelActivity;
use Nvl\Support\Enums\SystemActor;

trait ConfiguresGlobalActivityAdoptionEnvironment
{
    protected bool $adoptGlobalActivityStorage = false;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('nvl-activity.adoption.spatie', $this->adoptGlobalActivityStorage);
        $app['config']->set('activitylog.activity_model', HostActivity::class);
    }
}

uses(ConfiguresGlobalActivityAdoptionEnvironment::class);

it('keeps host global activity storage while recording and capturing into NVL storage', function (): void {
    app(ActivityCaptureContext::class)->run(SystemActor::Background, function (): void {
        config(['activitylog.activity_model' => HostActivity::class, 'activitylog.table_name' => 'host_activity']);
        $hostConfiguration = config('activitylog');
        $provider = new ActivityServiceProvider(app());
        $provider->register();
        $provider->boot();
        $this->configureActivityPolicies();
        expect(config('activitylog'))->toBe($hostConfiguration);
        Schema::create('host_activity', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('subject');
            $table->nullableMorphs('causer');
            $table->string('log_name')->nullable();
            $table->string('event')->nullable();
            $table->text('description');
            $table->json('properties')->nullable();
            $table->json('attribute_changes')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
        Schema::create('activity_mapping_subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        app(MappingRegistry::class)->register(new TestActivityMapping(TestActivitySubjectWithHasModelActivity::class));
        $subject = TestActivitySubjectWithHasModelActivity::query()->create(['name' => 'Before']);
        $subject->update(['name' => 'After']);
        app(ActivityRecorder::class)->record(['subject' => $subject, 'event' => 'reviewed'], SystemActor::Background);
        expect(ActivityLog::query()->count())->toBe(3)
            ->and($subject->activitiesAsSubject()->count())->toBe(3)
            ->and(HostActivity::query()->count())->toBe(0);
        HostActivity::query()->create(['description' => 'Host event']);
        expect(HostActivity::query()->count())->toBe(1)
            ->and(ActivityLog::query()->count())->toBe(3);

    });
});

it('never adopts third party global activity storage', function (): void {
    $this->adoptGlobalActivityStorage = true;
    $this->refreshApplication();
    $this->runDatabaseMigrations();
    expect(config('activitylog.activity_model'))->toBe(HostActivity::class);
});
