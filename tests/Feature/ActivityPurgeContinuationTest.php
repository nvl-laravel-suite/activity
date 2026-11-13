<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

test('a purge run deletes a bounded batch, releases its lock and continues after its cursor in a new job', function (): void {
    $table = (new ActivityLog)->getTable();
    $createdAt = now()->subDays(120)->format('Y-m-d H:i:s');
    $rows = [];
    for ($index = 0; $index < 10001; $index++) {
        $rows[] = ['id' => (string) Str::uuid(), 'description' => 'old', 'event' => 'created', 'created_at' => $createdAt, 'updated_at' => $createdAt];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table($table)->insert($chunk);
    }
    $lastId = DB::table($table)->orderBy('id')->offset(9999)->value('id');
    Bus::fake([PurgeActivityLogsJob::class]);
    $boundary = app(OwnershipBoundary::class);

    (new PurgeActivityLogsJob(90))->handle($boundary);

    $continuations = Bus::dispatched(PurgeActivityLogsJob::class);
    $lock = Cache::lock('nvl:activity:purge:'.hash('sha256', $boundary->key('activity.events', 'purge')), 60);
    expect(DB::table($table)->count())->toBe(1)
        ->and($continuations)->toHaveCount(1)
        ->and($continuations->first()->afterId)->toBe($lastId)
        ->and($continuations->first()->afterCreatedAt)->toStartWith($createdAt)
        ->and($continuations->first()->deletedBefore)->toBe(10000)
        ->and($lock->get())->toBeTrue();
    $lock->release();

    $continuations->first()->handle($boundary);

    expect(DB::table($table)->count())->toBe(0)
        ->and(Bus::dispatched(PurgeActivityLogsJob::class))->toHaveCount(1);
});

test('a purge job queued before continuations existed still runs from the beginning', function (): void {
    $job = new PurgeActivityLogsJob(90);
    unset($job->afterCreatedAt, $job->afterId, $job->deletedBefore);
    $old = ActivityLog::query()->create(['description' => 'old', 'event' => 'created', 'created_at' => now()->subDays(120)]);

    $restored = unserialize(serialize($job));
    $restored->handle(app(OwnershipBoundary::class));

    expect($restored->afterId)->toBeNull()
        ->and($restored->deletedBefore)->toBe(0)
        ->and($old->fresh())->toBeNull();
});
