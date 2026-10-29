<?php

declare(strict_types=1);

use Nvl\Activity\Definitions\Tables\ActivityTables;
use Nvl\Activity\Models\ActivityLog;

/** Complete runtime defaults; publication sections are declared in ../resources/config/sections.json. */
return [
    'models' => ['activity_log' => ActivityLog::class],
    'queue' => ['connection' => null, 'name' => null],
    'events' => [],
    'reads' => ['include_soft_deleted_subjects' => false],
    'name' => 'Activity',

    'migrations' => [
        'enabled' => true,
    ],

    'causer_suggestions' => [
        /*
         * Leave the model null to use the Eloquent model configured for the
         * application's "users" auth provider. Set a model explicitly when
         * Activity causers come from another Eloquent model.
         */
        'model' => null,
        'label_attribute' => 'name',
        'sublabel_attribute' => 'email',
        'type_attribute' => 'type',
        'search_attributes' => ['name', 'email'],
        'scan_limit' => 5000,
    ],

    'retention' => [
        'default_days' => 365,
        'system_logs_days' => 90,
        'allowed_purge_options' => [90, 365, 730],
        'external_visibility_timeout_seconds' => null,
        'lock_seconds' => 3600,
        'schedule' => [
            'enabled' => false,
            'time' => '02:00',
        ],
    ],

    'capture' => [
        'ignored_attributes' => [
            'created_at',
            'updated_at',
            'deleted_at',
            'remember_token',
        ],
    ],
    'connection' => null,
    'tables' => ['log' => ActivityTables::ActivityLog],
];
