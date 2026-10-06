<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Services\MappingRegistry;
use Nvl\Activity\Tests\Stubs\HostActivity;
use Nvl\Activity\Tests\Stubs\TestActivityMapping;
use Nvl\Activity\Tests\Stubs\TestActivitySubjectWithHasModelActivity;
use Nvl\Data\Services\TypeScriptSourceRegistry;

it('keeps host global activity storage while recording and capturing into NVL storage', function (): void {
    config(['activitylog.activity_model' => HostActivity::class, 'activitylog.table_name' => 'host_activity']);
    $hostConfiguration = config('activitylog');
    $provider = new ActivityServiceProvider(app());
    $provider->register();
    $provider->boot(app(TypeScriptSourceRegistry::class));
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
    app(ActivityRecorder::class)->record(subject: $subject, event: 'reviewed');
    expect(ActivityLog::query()->count())->toBe(3)
        ->and($subject->activitiesAsSubject()->count())->toBe(3)
        ->and(HostActivity::query()->count())->toBe(0);
    activity()->log('Host event');
    expect(HostActivity::query()->count())->toBe(1)
        ->and(ActivityLog::query()->count())->toBe(3);
});

it('adopts global Spatie activity storage only when explicitly requested', function (): void {
    config(['nvl-activity.adoption.spatie' => true, 'activitylog.activity_model' => HostActivity::class]);
    (new ActivityServiceProvider(app()))->register();
    expect(config('activitylog.activity_model'))->toBe(ActivityLog::class);
});
