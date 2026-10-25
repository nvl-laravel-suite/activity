<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityCaptureContext;
use Nvl\Activity\Services\MappingRegistry;
use Nvl\Activity\Services\NvlActivityLogger;
use Nvl\Activity\Tests\Fixtures\OwnershipActor;
use Nvl\Activity\Tests\Fixtures\OwnershipCompany;
use Nvl\Activity\Tests\Fixtures\OwnershipSubject;
use Nvl\Activity\Tests\OwnershipTestCase;
use Nvl\Activity\Tests\Stubs\TestActivityMapping;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OwnershipTestCase::class);

it('captures audit facts and groups subject or causer queries inside their native owner', function (): void {
    app(ActivityCaptureContext::class)->run(SystemActor::Background, function (): void {
        $runner = app(OwnershipRunner::class);
        $actor = OwnershipActor::query()->firstOrFail();
        $foreign = null;
        foreach ([7, 8] as $id) {
            $runner->run(OwnershipCompany::query()->findOrFail($id), $actor, function () use ($id, $actor, &$foreign): void {
                $subject = app(OwnershipWriter::class)->create(OwnershipSubject::class, fn (OwnershipAssignment $assignment): OwnershipSubject => OwnershipSubject::query()->create([...$assignment->attributes(), 'id' => $id, 'name' => 'Owned subject']));
                $fact = app(NvlActivityLogger::class)->performedOn($subject)->causedBy($actor)->log('changed');
                expect($fact)->toBeInstanceOf(ActivityLog::class)
                    ->and(ActivityLog::query()->where('subject_id', 7)->orWhere('causer_id', 1)->count())->toBe(1);
                if ($id === 7) {
                    $foreign = $fact;
                }
                $loaded = ActivityLog::query()->with(['subject', 'causer'])->firstOrFail();
                expect($loaded->subject?->getKey())->toBe($id)
                    ->and($loaded->causer?->getAttributes())->not->toHaveKey('password');
            });
        }
        $runner->run(OwnershipCompany::query()->findOrFail(8), $actor, fn () => expect(fn () => $foreign?->subject)->toThrow(OwnershipViolation::class));

    });
});

it('runs captured retention under its producer owner and restores the surrounding owner', function (): void {
    app(ActivityCaptureContext::class)->run(SystemActor::Background, function (): void {
        $runner = app(OwnershipRunner::class);
        $actor = OwnershipActor::query()->firstOrFail();
        $job = $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, function (): PurgeActivityLogsJob {
            ActivityLog::query()->create(['description' => 'old A', 'created_at' => now()->subDays(120)]);

            return new PurgeActivityLogsJob(90);
        });
        $runner->run(OwnershipCompany::query()->findOrFail(8), $actor, function () use ($job): void {
            ActivityLog::query()->create(['description' => 'old B', 'created_at' => now()->subDays(120)]);
            app(Dispatcher::class)->dispatchSync($job);
            expect(ActivityLog::query()->pluck('description')->all())->toBe(['old B']);
        });
        $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, fn () => expect(ActivityLog::query()->count())->toBe(0));

    });
});

it('keeps retention deletion inside the canonical source write fence', function (): void {
    app(ActivityCaptureContext::class)->run(SystemActor::Background, function (): void {
        config(['database.connections.activity_escape' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $other = DB::connection('activity_escape');
        $other->getSchemaBuilder()->create((new ActivityLog)->getTable(), static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('description');
        });
        $fact = app(OwnershipRunner::class)->run(OwnershipCompany::query()->findOrFail(7), OwnershipActor::query()->firstOrFail(), fn () => ActivityLog::query()->create(['description' => 'old', 'created_at' => now()->subDays(120)]));
        $other->table($fact->getTable())->insert(['id' => $fact->getKey(), 'description' => 'foreign']);
        ActivityLog::deleting(static function (ActivityLog $model): void {
            $model->setConnection('activity_escape');
        });
        expect(fn () => app(OwnershipRunner::class)->run(OwnershipCompany::query()->findOrFail(7), OwnershipActor::query()->firstOrFail(), fn () => app(Dispatcher::class)->dispatchSync(new PurgeActivityLogsJob(90))))->toThrow(OwnershipViolation::class);
        expect($other->table($fact->getTable())->value('description'))->toBe('foreign')
            ->and(DB::table($fact->getTable())->where('id', $fact->getKey())->exists())->toBeTrue();

    });
});

it('keeps automatic create update and deleted-subject evidence in the captured owner', function (): void {
    app(ActivityCaptureContext::class)->run(SystemActor::Background, function (): void {
        app(MappingRegistry::class)->register(new TestActivityMapping(OwnershipSubject::class));
        $runner = app(OwnershipRunner::class);
        $actor = OwnershipActor::query()->firstOrFail();
        $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, function (): void {
            $subject = app(OwnershipWriter::class)->create(OwnershipSubject::class, fn (OwnershipAssignment $assignment): OwnershipSubject => OwnershipSubject::query()->create([...$assignment->attributes(), 'id' => 30, 'name' => 'Initial']));
            $subject->forceFill(['name' => 'Changed'])->save();
            $subject->delete();
            $facts = ActivityLog::query()->with('subject')->oldestFirst()->get();
            expect($facts->pluck('event')->all())->toBe(['created', 'updated', 'deleted'])
                ->and($facts->pluck('owner_scope_key')->unique())->toHaveCount(1)
                ->and($facts->pluck('subject')->filter())->toHaveCount(0);
        });
        $runner->run(OwnershipCompany::query()->findOrFail(8), $actor, fn () => expect(ActivityLog::query()->count())->toBe(0));

    });
});
