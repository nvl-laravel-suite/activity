<?php

declare(strict_types=1);

use Nvl\Activity\Enums\ActivityDoctorSeverity;
use Nvl\Activity\Enums\ActivityEvent;
use Nvl\Activity\Enums\ActivityImportance;
use Nvl\Activity\Enums\ActivityResponseCode;
use Nvl\Activity\Enums\ActivitySource;
use Nvl\Activity\Enums\ActivityVisibility;
use Nvl\Activity\Enums\EntrySource;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityDoctor;
use Nvl\Activity\Services\ActivityTransformService;

test('activity headlines and source labels ship with standalone English translations', function (): void {
    app()->setLocale('en');

    $keys = [
        'nvl-activity::activity/general.headline',
        'nvl-activity::activity/general.summary.single_attribute',
        'nvl-activity::activity/general.summary.multiple_attributes',
        'nvl-activity::activity/general.changes.from_to',
        'nvl-activity::activity/general.changes.to_only',
        'nvl-activity::activity/general.changes.from_only',
        'nvl-activity::activity/general.changes.empty_value',
        'nvl-activity::activity/general.changes.unknown_attribute',
        'nvl-activity::activity/general.templates.updated',
        'nvl-activity::activity/general.templates.updated_field',
        'nvl-activity::activity/general.templates.updated_field_value',
        'nvl-activity::activity/general.templates.updated_fields',
        'nvl-activity::activity/general.templates.status_changed_to',
    ];

    foreach ($keys as $key) {
        expect(trans($key))->not->toBe($key);
    }

    foreach (EntrySource::cases() as $source) {
        expect($source->getLabel())->not->toContain('nvl-activity::');
    }

    foreach ([
        ...ActivityImportance::cases(),
        ...ActivitySource::cases(),
        ...ActivityVisibility::cases(),
        ...ActivityDoctorSeverity::cases(),
    ] as $enum) {
        expect($enum->getLabel())->not->toContain('nvl-activity::');
    }

    foreach (ActivityResponseCode::cases() as $responseCode) {
        expect($responseCode->getMessage())->not->toContain('nvl-activity::');
    }
});

test('standard activity events provide localized labels and headline templates', function (): void {
    foreach (['en', 'bg'] as $locale) {
        app()->setLocale($locale);

        foreach (ActivityEvent::cases() as $event) {
            $templateKey = "nvl-activity::activity/general.templates.{$event->value}";

            expect($event->getLabel())->not->toContain('nvl-activity::')
                ->and(trans($templateKey))->not->toBe($templateKey);
        }
    }

    app()->setLocale('en');
});

test('bundled English and Bulgarian catalogs have key parity', function (): void {
    $flatten = function (array $values, string $prefix = '') use (&$flatten): array {
        $keys = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $keys[] = $path;

            if (is_array($value)) {
                $keys = [...$keys, ...$flatten($value, $path)];
            }
        }

        return $keys;
    };

    $english = require __DIR__.'/../../lang/en/activity/general.php';
    $bulgarian = require __DIR__.'/../../lang/bg/activity/general.php';

    expect($flatten($bulgarian))->toBe($flatten($english));
});

test('system actors use the active package locale', function (): void {
    app()->setLocale('bg');

    try {
        $activity = new ActivityLog;
        $activity->forceFill([
            'id' => 'activity-1',
            'description' => 'Recorded',
            'event' => 'activity_logged',
            'properties' => [],
        ]);
        $activity->setRelation('causer', null);
        $activity->setRelation('subject', null);

        $item = app(ActivityTransformService::class)->normalizeActivity($activity);

        expect($item->headline)->toStartWith('Система ');
    } finally {
        app()->setLocale('en');
    }
});

test('operational enum labels and doctor messages follow the active package locale', function (): void {
    app()->setLocale('bg');

    try {
        $checks = collect(app(ActivityDoctor::class)->inspect())->keyBy('key');

        expect(ActivityImportance::Important->getLabel())->toBe('Важна')
            ->and(ActivitySource::User->getLabel())->toBe('Потребител')
            ->and(ActivityVisibility::AuditOnly->getLabel())->toBe('Само за одит')
            ->and(ActivityDoctorSeverity::Warning->getLabel())->toBe('Предупреждение')
            ->and(ActivityResponseCode::PurgeQueued->getMessage())
            ->toBe('Почистването на дневника на активността е добавено към опашката.')
            ->and($checks->get('schema.table')?->message)
            ->toContain('Таблицата');
    } finally {
        app()->setLocale('en');
    }
});
