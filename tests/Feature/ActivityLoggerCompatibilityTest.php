<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Exceptions\ActivityConfigurationException;
use Nvl\Activity\Models\ActivityLog;
use Spatie\Activitylog\Support\ActivityLogger;
use Spatie\Activitylog\Support\LogOptions;

test('the canonical activity logger exposes no per-write table override', function (): void {
    $loggerClass = class_exists(ActivityLogger::class)
        ? ActivityLogger::class
        : 'Spatie\\Activitylog\\ActivityLogger';

    expect($loggerClass::hasMacro('onTable'))->toBeFalse();
});

test('Activitylog v5 namespaces are the only supported source contract', function (): void {
    expect(trait_exists('Spatie\\Activitylog\\Models\\Concerns\\LogsActivity'))->toBeTrue()
        ->and(class_exists(ActivityLogger::class))->toBeTrue()
        ->and(class_exists(LogOptions::class))->toBeTrue()
        ->and(trait_exists('Spatie\\Activitylog\\Traits\\LogsActivity'))->toBeFalse()
        ->and(class_exists('Spatie\\Activitylog\\LogOptions'))->toBeFalse();
});

test('the canonical activity model rejects an empty configured table', function (): void {
    config()->set('activity.storage.table', ' ');

    (new ActivityLog)->getTable();
})->throws(ActivityConfigurationException::class, 'Activity table name cannot be empty.');

test('empty activity logs are disabled for the installed log options version', function (): void {
    $logOptions = LogOptions::defaults();

    expect($logOptions->dontLogEmptyChanges())
        ->toBe($logOptions);
    expect($logOptions->logEmptyChanges)->toBeFalse();
});

test('the activity schema supports batched activity logs', function (): void {
    expect(Schema::hasColumn('activity_log', 'batch_uuid'))->toBeTrue();
});
