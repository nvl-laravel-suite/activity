<?php

declare(strict_types=1);

use Nvl\Activity\Data\ActivityIndexFilter;

test('activity index filters clamp per page request values', function (int $requested, int $expected): void {
    $filters = ActivityIndexFilter::fromInput(['per_page' => $requested]);

    expect($filters->perPage)->toBe($expected);
})->with([
    'zero clamps to minimum' => [0, 1],
    'one stays one' => [1, 1],
    'hundred stays hundred' => [100, 100],
    'large value clamps to maximum' => [10000, 100],
]);

test('activity index filters expand date only upper bounds to end of day', function (): void {
    $filters = ActivityIndexFilter::fromInput([
        'created_at_from' => '2026-03-22',
        'created_at_to' => '2026-03-22',
    ]);

    expect($filters->createdAtFrom?->toDateTimeString())->toBe('2026-03-22 00:00:00')
        ->and($filters->createdAtTo?->toDateTimeString())->toBe('2026-03-22 23:59:59');
});

test('activity index filters read subject filters', function (): void {
    $filters = ActivityIndexFilter::fromInput([
        'subject_type' => 'Domain\\Content\\Article',
        'subject_id' => '9c9a0ae5-fa1f-4138-9bc1-6ce36880c2b3',
    ]);

    expect($filters->subjectType)->toBe('Domain\\Content\\Article')
        ->and($filters->subjectId)->toBe('9c9a0ae5-fa1f-4138-9bc1-6ce36880c2b3');
});

test('activity index filters preserve timestamp upper bounds', function (): void {
    $filters = ActivityIndexFilter::fromInput([
        'created_at_to' => '2026-03-22 09:30:00',
    ]);

    expect($filters->createdAtTo?->toDateTimeString())->toBe('2026-03-22 09:30:00');
});
