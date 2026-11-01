<?php

declare(strict_types=1);

use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityReader;
use Nvl\Support\Enums\SystemActor;

test('native activity filters accept event lists and reject transport comma strings', function (): void {
    ActivityLog::query()->create(['description' => 'row', 'event' => 'created']);
    expect(app(ActivityReader::class)->paginate(['events' => ['created']], SystemActor::Background)->total())->toBe(1);
    expect(fn () => app(ActivityReader::class)->paginate(['events' => 'created,updated'], SystemActor::Background))->toThrow(InvalidArgumentException::class);
});

test('native activity filters reject oversized event lists', function (): void {
    expect(fn () => app(ActivityReader::class)->paginate(['events' => array_fill(0, 101, 'created')], SystemActor::Background))->toThrow(InvalidArgumentException::class);
});

test('native activity filters reject out of bounds page sizes', function (int $limit): void {
    expect(fn () => app(ActivityReader::class)->paginate(['per_page' => $limit], SystemActor::Background))->toThrow(InvalidArgumentException::class);
})->with([0, -1, 101, 1000]);

test('native activity filters preserve exact date boundaries', function (): void {
    ActivityLog::query()->create(['description' => 'noon', 'event' => 'created', 'created_at' => '2026-08-01 12:00:00']);
    expect(app(ActivityReader::class)->paginate(['created_at_to' => '2026-08-01 00:00:00'], SystemActor::Background)->total())->toBe(0);
});

test('native activity filters preserve exact subject pairs', function (): void {
    ActivityLog::query()->create(['description' => 'row', 'event' => 'created', 'subject_type' => 'record', 'subject_id' => 'one']);
    expect(app(ActivityReader::class)->paginate(['subject_type' => 'record', 'subject_id' => 'other'], SystemActor::Background)->total())->toBe(0);
});

test('native activity filters accept timestamp upper bounds', function (): void {
    ActivityLog::query()->create(['description' => 'noon', 'event' => 'created', 'created_at' => '2026-08-01 12:00:00']);
    expect(app(ActivityReader::class)->paginate(['created_at_to' => '2026-08-01 12:00:00'], SystemActor::Background)->total())->toBe(1);
});

test('activity search treats wildcard and escape characters as literal text', function (string $term): void {
    $match = ActivityLog::query()->create(['description' => 'literal '.$term.' value', 'event' => 'created']);
    ActivityLog::query()->create(['description' => 'unrelated value', 'event' => 'created']);

    expect(app(ActivityReader::class)->paginate(['search' => $term], SystemActor::Background)->pluck('id')->all())->toBe([$match->id]);
    expect(app(ActivityReader::class)->cursor(['search' => $term], SystemActor::Background)['items']->pluck('id')->all())->toBe([$match->id]);
})->with(['%', '_', '!', '\\', "'", 'Пример']);

test('activity search rejects invalid raw terms on both public read paths', function (string $term): void {
    expect(fn () => app(ActivityReader::class)->paginate(['search' => $term], SystemActor::Background))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ActivityReader::class)->causers(SystemActor::Background, $term))->toThrow(InvalidArgumentException::class);
})->with([
    'oversized literal' => [str_repeat('x', 201)],
    'oversized whitespace' => [str_repeat(' ', 201)],
    'leading NUL' => ["\0Operator"],
    'trailing NUL' => ["Operator\0"],
    'invalid UTF8' => ["\xff"],
]);
