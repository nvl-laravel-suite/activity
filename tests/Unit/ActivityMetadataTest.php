<?php

declare(strict_types=1);

use Nvl\Activity\Support\ActivityMetadata;

it('bounds immutable JSON records before persistence', function (): void {
    expect(fn () => ActivityMetadata::record(['large' => str_repeat('a', 65537)], 'context'))->toThrow(InvalidArgumentException::class);
    expect(fn () => ActivityMetadata::record(['number' => INF], 'context'))->toThrow(InvalidArgumentException::class);
});
