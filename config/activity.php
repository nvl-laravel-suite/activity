<?php

declare(strict_types=1);

return [
    'name' => 'Activity',

    'routes' => [
        'enabled' => false,
        'prefix' => 'api/v1',
        'middleware' => ['api'],
        'management_middleware' => ['auth', 'throttle:60,1'],
        'timeline_subjects' => [],
    ],

    'authorization' => [
        'abilities' => [
            'view' => null,
            'timeline' => null,
            'purge' => null,
        ],
    ],

    'migrations' => [
        'enabled' => true,
    ],

    'storage' => [
        'connection' => null,
        'table' => 'activity_log',
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
        'queue' => 'maintenance',
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
];
