<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Contracts\ActivityAuthorization;
use Nvl\Activity\Exceptions\ActivityConfigurationException;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityCaptureContext;
use Nvl\Activity\Services\ActivityPolicyRegistry;
use Nvl\Activity\Services\MappingRegistry;
use Nvl\Activity\Support\ActivityEventPolicy;
use Nvl\Activity\Tests\Stubs\TestActivityMapping;
use Nvl\Activity\Tests\Stubs\TestActivitySubjectWithHasModelActivity;
use Nvl\Activity\Traits\HasModelActivity;
use Nvl\Support\Enums\SystemActor;

beforeEach(function (): void {
    Schema::create('activity_mapping_subjects', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('activity_mapping_subjects');
});

test('registered mappings own automatic model capture options and log names', function (): void {
    app(ActivityCaptureContext::class)->run(SystemActor::Background, function (): void {
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
});

test('native changes use their dedicated column without a legacy properties fallback', function (): void {
    app(ActivityCaptureContext::class)->run(SystemActor::Background, function (): void {
        $activity = new ActivityLog;
        $activity->forceFill([
            'attribute_changes' => null,
            'properties' => [
                'attributes' => ['name' => 'Updated name'],
                'old' => ['name' => 'Initial name'],
                'consumer_context' => ['source' => 'test'],
            ],
        ]);

        expect($activity->attribute_changes)->toBeNull();

    });
});

test('registered mappings capture the complete create update and delete lifecycle', function (): void {
    app(ActivityCaptureContext::class)->run(SystemActor::Background, function (): void {
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
});

test('rolled back model mutations never leave automatic activity rows behind', function (): void {
    app(ActivityCaptureContext::class)->run(SystemActor::Background, function (): void {
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
});

test('separate activity storage cannot share a business connection rollback', function (): void {
    app(ActivityCaptureContext::class)->run(SystemActor::Background, function (): void {
        $storageConnection = 'activity_capture_storage';
        $storageTable = 'activity_capture_log';
        $originalConnection = config('nvl-activity.connection');
        $originalTable = config('nvl-activity.tables.log');

        config()->set("database.connections.{$storageConnection}", [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('nvl-activity.connection', $storageConnection);
        config()->set('nvl-activity.tables.log', $storageTable);
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
            config()->set('nvl-activity.connection', $originalConnection);
            config()->set('nvl-activity.tables.log', $originalTable);
            DB::purge($storageConnection);
            config()->set("database.connections.{$storageConnection}", null);
        }

    });
});

test('models using the shared trait remain silent until a mapping is registered', function (): void {
    app(ActivityCaptureContext::class)->run(SystemActor::Background, function (): void {
        TestActivitySubjectWithHasModelActivity::query()->create([
            'name' => 'Unmapped subject',
        ]);

        expect(ActivityLog::query()->count())->toBe(0);

    });
});

test('duplicate model mappings fail instead of changing behavior with provider order', function (): void {
    app(ActivityCaptureContext::class)->run(SystemActor::Background, function (): void {
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
});

test('mapped capture rejects missing and denied context before autocommitted model creation', function (): void {
    app(MappingRegistry::class)->register(new TestActivityMapping(TestActivitySubjectWithHasModelActivity::class));
    expect(fn () => TestActivitySubjectWithHasModelActivity::query()->create(['name' => 'Missing scope']))
        ->toThrow(AuthorizationException::class);
    expect(TestActivitySubjectWithHasModelActivity::query()->count())->toBe(0)->and(ActivityLog::query()->count())->toBe(0);
    $this->app->offsetUnset(ActivityAuthorization::class);
    expect(fn () => app(ActivityCaptureContext::class)->run(SystemActor::Background, fn () => TestActivitySubjectWithHasModelActivity::query()->create(['name' => 'Denied policy'])))
        ->toThrow(AuthorizationException::class);
    expect(TestActivitySubjectWithHasModelActivity::query()->count())->toBe(0)->and(ActivityLog::query()->count())->toBe(0);
});

test('mapped capture participates in a shared host outer transaction', function (): void {
    app(MappingRegistry::class)->register(new TestActivityMapping(TestActivitySubjectWithHasModelActivity::class));
    app(ActivityCaptureContext::class)->run(SystemActor::Background, function (): void {
        DB::beginTransaction();
        TestActivitySubjectWithHasModelActivity::query()->create(['name' => 'Rolled back']);
        DB::rollBack();
        expect(TestActivitySubjectWithHasModelActivity::query()->count())->toBe(0)->and(ActivityLog::query()->count())->toBe(0);
        DB::beginTransaction();
        TestActivitySubjectWithHasModelActivity::query()->create(['name' => 'Committed']);
        DB::commit();
        expect(TestActivitySubjectWithHasModelActivity::query()->count())->toBe(1)->and(ActivityLog::query()->count())->toBe(1);
    });
});

test('nullable mapped updates are authorized before source mutation', function (bool $denyPolicy): void {
    $subject = TestActivitySubjectWithHasModelActivity::query()->create(['name' => null]);
    app(MappingRegistry::class)->register(new TestActivityMapping(TestActivitySubjectWithHasModelActivity::class));
    if ($denyPolicy) {
        $this->app->offsetUnset(ActivityAuthorization::class);
    }
    $update = fn () => $subject->update(['name' => 'Pending value']);
    $operation = $denyPolicy
        ? fn () => app(ActivityCaptureContext::class)->run(SystemActor::Background, $update)
        : $update;
    expect($operation)->toThrow(AuthorizationException::class);
    expect(DB::transactionLevel())->toBe(0)
        ->and($subject->fresh()->name)->toBeNull()
        ->and(ActivityLog::query()->count())->toBe(0);
})->with(['missing capture context' => false, 'denied host policy' => true]);

test('nullable mapped no op updates remain silent without capture authority', function (): void {
    $subject = TestActivitySubjectWithHasModelActivity::query()->create(['name' => null]);
    app(MappingRegistry::class)->register(new TestActivityMapping(TestActivitySubjectWithHasModelActivity::class));
    $this->app->offsetUnset(ActivityAuthorization::class);
    $subject->update(['name' => null]);
    expect($subject->fresh()->name)->toBeNull()->and(ActivityLog::query()->count())->toBe(0);
});

test('nullable mapped authorized updates preserve pending and original diff facts', function (): void {
    $subject = TestActivitySubjectWithHasModelActivity::query()->create(['name' => null]);
    app(MappingRegistry::class)->register(new TestActivityMapping(TestActivitySubjectWithHasModelActivity::class));
    app(ActivityCaptureContext::class)->run(SystemActor::Background, fn () => $subject->update(['name' => 'Pending value']));
    $fact = ActivityLog::query()->sole();
    expect($fact->attribute_changes->get('attributes'))->toBe(['name' => 'Pending value'])
        ->and($fact->attribute_changes->get('old'))->toBe(['name' => null]);
});

test('capture rejects event policy fields before changing an autocommitted subject', function (): void {
    $subject = TestActivitySubjectWithHasModelActivity::query()->create(['name' => 'Before']);
    app(MappingRegistry::class)->register(new TestActivityMapping(TestActivitySubjectWithHasModelActivity::class));
    $policies = new ActivityPolicyRegistry;
    $policies->register(new ActivityEventPolicy('updated'));
    app()->instance(ActivityPolicyRegistry::class, $policies);

    expect(fn () => app(ActivityCaptureContext::class)->run(SystemActor::Background, fn () => $subject->update(['name' => 'Denied'])))
        ->toThrow(InvalidArgumentException::class);
    expect($subject->fresh()->name)->toBe('Before')
        ->and(ActivityLog::query()->count())->toBe(0);
});

test('native capture preserves cast values and emits one event per soft lifecycle transition', function (): void {
    Schema::table('activity_mapping_subjects', function (Blueprint $table): void {
        $table->softDeletes();
    });
    $subject = new class extends Model
    {
        use HasModelActivity;
        use SoftDeletes;

        protected $table = 'activity_mapping_subjects';

        protected $guarded = [];

        public $timestamps = false;

        protected function casts(): array
        {
            return ['name' => 'array'];
        }
    };
    app(MappingRegistry::class)->register(new TestActivityMapping($subject::class));
    app(ActivityCaptureContext::class)->run(SystemActor::Background, function () use ($subject): void {
        $subject->fill(['name' => ['en' => 'Initial']])->save();
        $subject->update(['name' => ['en' => 'Changed']]);
        $subject->delete();
        $subject->restore();
    });
    $facts = ActivityLog::query()->oldestFirst()->get();
    expect($facts->pluck('event')->all())->toBe(['created', 'updated', 'deleted', 'restored'])
        ->and($facts[1]->attribute_changes->get('attributes'))->toBe(['name' => ['en' => 'Changed']])
        ->and($facts[1]->attribute_changes->get('old'))->toBe(['name' => ['en' => 'Initial']]);
});
