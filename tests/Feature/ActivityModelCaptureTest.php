<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Exceptions\ActivityConfigurationException;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\MappingRegistry;
use Nvl\Activity\Tests\Stubs\TestActivityMapping;
use Nvl\Activity\Tests\Stubs\TestActivitySubjectWithHasModelActivity;

beforeEach(function (): void {
    Schema::create('activity_mapping_subjects', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
});

afterEach(function (): void {
    Schema::dropIfExists('activity_mapping_subjects');
});

test('registered mappings own automatic model capture options and log names', function (): void {
    app(MappingRegistry::class)->register(
        new TestActivityMapping(TestActivitySubjectWithHasModelActivity::class),
    );

    $subject = TestActivitySubjectWithHasModelActivity::query()->create([
        'name' => 'Initial name',
    ]);

    $subject->update(['name' => 'Updated name']);

    $activities = ActivityLog::query()->oldestFirst()->get();

    expect($activities)->toHaveCount(2)
        ->and($activities->pluck('log_name')->all())->toBe(['mapped', 'mapped'])
        ->and($activities->pluck('event')->all())->toBe(['created', 'updated'])
        ->and($activities->last()?->attribute_changes?->get('attributes'))
        ->toBe(['name' => 'Updated name'])
        ->and($activities->last()?->attribute_changes?->get('old'))
        ->toBe(['name' => 'Initial name']);
});

test('tracked changes remain readable from the Spatie v4 properties layout', function (): void {
    $activity = new ActivityLog;
    $activity->forceFill([
        'attribute_changes' => null,
        'properties' => [
            'attributes' => ['name' => 'Updated name'],
            'old' => ['name' => 'Initial name'],
            'consumer_context' => ['source' => 'test'],
        ],
    ]);

    expect($activity->attribute_changes?->all())->toBe([
        'attributes' => ['name' => 'Updated name'],
        'old' => ['name' => 'Initial name'],
    ]);
});

test('registered mappings capture the complete create update and delete lifecycle', function (): void {
    app(MappingRegistry::class)->register(
        new TestActivityMapping(TestActivitySubjectWithHasModelActivity::class),
    );

    $subject = TestActivitySubjectWithHasModelActivity::query()->create([
        'name' => 'Lifecycle subject',
    ]);

    $subject->update(['name' => 'Updated lifecycle subject']);
    $subject->update(['name' => 'Updated lifecycle subject']);

    expect(ActivityLog::query()->count())->toBe(2);

    $subject->delete();

    $activities = ActivityLog::query()->oldestFirst()->get();

    expect($activities)->toHaveCount(3)
        ->and($activities->pluck('log_name')->all())->toBe(['mapped', 'mapped', 'mapped'])
        ->and($activities->pluck('event')->all())->toBe(['created', 'updated', 'deleted'])
        ->and($activities->last()?->subject_type)->toBe($subject->getMorphClass())
        ->and($activities->last()?->subject_id)->toBe((string) $subject->getKey());
});

test('rolled back model mutations never leave automatic activity rows behind', function (): void {
    app(MappingRegistry::class)->register(
        new TestActivityMapping(TestActivitySubjectWithHasModelActivity::class),
    );

    expect(fn () => DB::transaction(function (): never {
        TestActivitySubjectWithHasModelActivity::query()->create([
            'name' => 'Rolled back subject',
        ]);

        throw new RuntimeException('Rollback the subject mutation.');
    }))->toThrow(RuntimeException::class, 'Rollback the subject mutation.');

    expect(TestActivitySubjectWithHasModelActivity::query()->count())->toBe(0)
        ->and(ActivityLog::query()->count())->toBe(0);
});

test('separate activity storage cannot share a business connection rollback', function (): void {
    $storageConnection = 'activity_capture_storage';
    $storageTable = 'activity_capture_log';
    $originalConnection = config('activity.storage.connection');
    $originalTable = config('activity.storage.table');

    config()->set("database.connections.{$storageConnection}", [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('activity.storage.connection', $storageConnection);
    config()->set('activity.storage.table', $storageTable);
    Schema::connection($storageConnection)->create($storageTable, function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('log_name')->nullable();
        $table->text('description');
        $table->string('subject_type')->nullable();
        $table->string('subject_id')->nullable();
        $table->string('event')->nullable();
        $table->string('causer_type')->nullable();
        $table->string('causer_id')->nullable();
        $table->json('attribute_changes')->nullable();
        $table->json('properties')->nullable();
        $table->uuid('batch_uuid')->nullable();
        $table->timestamps();
    });

    try {
        app(MappingRegistry::class)->register(
            new TestActivityMapping(TestActivitySubjectWithHasModelActivity::class),
        );

        expect(fn () => DB::transaction(function (): never {
            TestActivitySubjectWithHasModelActivity::query()->create([
                'name' => 'Cross-connection rollback subject',
            ]);

            throw new RuntimeException('Rollback only the business connection.');
        }))->toThrow(RuntimeException::class, 'Rollback only the business connection.');

        expect(TestActivitySubjectWithHasModelActivity::query()->count())->toBe(0)
            ->and(ActivityLog::query()->count())->toBe(1);
    } finally {
        config()->set('activity.storage.connection', $originalConnection);
        config()->set('activity.storage.table', $originalTable);
        DB::purge($storageConnection);
        config()->set("database.connections.{$storageConnection}", null);
    }
});

test('models using the shared trait remain silent until a mapping is registered', function (): void {
    TestActivitySubjectWithHasModelActivity::query()->create([
        'name' => 'Unmapped subject',
    ]);

    expect(ActivityLog::query()->count())->toBe(0);
});

test('duplicate model mappings fail instead of changing behavior with provider order', function (): void {
    $mapping = new TestActivityMapping(TestActivitySubjectWithHasModelActivity::class);
    $registry = app(MappingRegistry::class);

    $registry->register($mapping);
    $registry->register($mapping);

    expect(fn () => $registry->register(
        new TestActivityMapping(TestActivitySubjectWithHasModelActivity::class),
    ))->toThrow(
        ActivityConfigurationException::class,
        'Only one activity mapping may be registered for a model.',
    );
});
