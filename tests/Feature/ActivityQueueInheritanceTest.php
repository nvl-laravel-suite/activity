<?php

declare(strict_types=1);

use Nvl\Activity\Jobs\PurgeActivityLogsJob;

it('selects the purge queue from package Core or effective Laravel defaults', function (array $overrides, string $connection, string $queue): void {
    config([
        'nvl-activity.queue' => ['connection' => null, 'name' => null],
        'nvl-core.queue' => ['connection' => null, 'name' => null],
        'queue.default' => 'host', 'queue.connections.host.queue' => 'host-work',
        ...$overrides,
    ]);
    $job = new PurgeActivityLogsJob(30);
    expect($job->connection)->toBe($connection)->and($job->queue)->toBe($queue);
})->with([
    'Laravel' => [[], 'host', 'host-work'],
    'Core' => [['nvl-core.queue' => ['connection' => 'suite', 'name' => 'suite-work']], 'suite', 'suite-work'],
    'package' => [['nvl-activity.queue' => ['connection' => 'activity', 'name' => 'activity-work'], 'nvl-core.queue.connection' => 'suite'], 'activity', 'activity-work'],
    'explicit sync' => [['nvl-activity.queue' => ['connection' => 'sync', 'name' => 'inline']], 'sync', 'inline'],
    'explicit package override' => [['nvl-activity.queue.name' => 'old-work'], 'host', 'old-work'],
]);
