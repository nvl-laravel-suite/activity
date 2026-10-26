<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Nvl\Activity\Enums\ActivityResponseCode;
use Nvl\Activity\Exceptions\ActivityConfigurationException;
use Nvl\Activity\Exceptions\ActivityPurgeCriteriaException;
use Nvl\Activity\Support\ActivityPurgeCriteria;
use Nvl\Activity\Support\ActivityRecordEnvelope;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Activity\Tests\Fixtures\ConsumerSupport\PackageExceptionRenderer;

test('purge criteria normalize console values and summarize every active filter', function (): void {
    $criteria = ActivityPurgeCriteria::fromConsoleOptions([
        'before' => '2026-03-01 10:00:00 UTC',
        'after' => '2026-02-01 10:00:00 UTC',
        'system-only' => true,
        'include-important' => true,
        'event' => [' created ', 'created', '', null, ['nested']],
        'log-name' => ' audit ',
        'subject-type' => [' App\\Models\\Order '],
        'subject-id' => [' 42 '],
        'causer-type' => [' App\\Models\\User '],
        'causer-id' => [' 7 '],
    ]);

    expect($criteria->days)->toBeNull()
        ->and($criteria->before)->toBe('2026-03-01T10:00:00+00:00')
        ->and($criteria->after)->toBe('2026-02-01T10:00:00+00:00')
        ->and($criteria->systemOnly)->toBeTrue()
        ->and($criteria->includeImportant)->toBeTrue()
        ->and($criteria->events)->toBe(['created'])
        ->and($criteria->logNames)->toBe(['audit'])
        ->and($criteria->subjectTypes)->toBe(['App\\Models\\Order'])
        ->and($criteria->subjectIds)->toBe(['42'])
        ->and($criteria->causerTypes)->toBe(['App\\Models\\User'])
        ->and($criteria->causerIds)->toBe(['7'])
        ->and($criteria->cutoff()->toIso8601String())->toBe('2026-03-01T10:00:00+00:00')
        ->and($criteria->afterCutoff()?->toIso8601String())->toBe('2026-02-01T10:00:00+00:00')
        ->and($criteria->summaryParts())->toHaveCount(10);
});

test('days criteria resolve relative cutoffs and ignore non-list filter input', function (): void {
    $this->travelTo('2026-04-10 12:00:00');

    $criteria = ActivityPurgeCriteria::fromConsoleOptions([
        'days' => 10,
        'event' => new stdClass,
    ]);

    expect($criteria->cutoff()->toDateTimeString())->toBe('2026-03-31 12:00:00')
        ->and($criteria->afterCutoff())->toBeNull()
        ->and($criteria->events)->toBe([])
        ->and(ActivityPurgeCriteria::fromDays(5, true, true)->systemOnly)->toBeTrue()
        ->and(ActivityPurgeCriteria::fromDays(5, true, true)->includeImportant)->toBeTrue();
});

test('purge criteria reject ambiguous, absent, inverted, and malformed cutoffs', function (Closure $operation, string $message): void {
    expect($operation)->toThrow(ActivityPurgeCriteriaException::class, $message);
})->with([
    'relative and absolute cutoffs' => [
        static fn (): ActivityPurgeCriteria => ActivityPurgeCriteria::fromConsoleOptions([
            'days' => 30,
            'before' => '2026-01-01',
        ]),
        'Use either --days or --before, not both.',
    ],
    'no cutoff' => [
        static fn (): ActivityPurgeCriteria => ActivityPurgeCriteria::fromConsoleOptions([]),
        'Either --days or --before must be provided.',
    ],
    'invalid date' => [
        static fn (): ActivityPurgeCriteria => ActivityPurgeCriteria::fromConsoleOptions(['before' => 'not-a-date']),
        'The --before option must be a valid date/time.',
    ],
    'non scalar days' => [
        static fn (): ActivityPurgeCriteria => ActivityPurgeCriteria::fromConsoleOptions(['days' => []]),
        'Days must be a positive integer.',
    ],
    'non positive days' => [
        static fn (): ActivityPurgeCriteria => ActivityPurgeCriteria::fromDays(0),
        'Days must be a positive integer.',
    ],
    'inverted range' => [
        static fn (): ActivityPurgeCriteria => ActivityPurgeCriteria::fromConsoleOptions([
            'before' => '2026-01-01',
            'after' => '2026-01-01',
        ]),
        '--after must be earlier than the effective purge cutoff.',
    ],
    'unresolved cutoff' => [
        static fn (): CarbonImmutable => (new ActivityPurgeCriteria)->cutoff(),
        'Purge criteria requires either days or before.',
    ],
]);

test('immutable envelopes retain explicit null metadata across delivery serialization', function (): void {
    $envelope = new ActivityRecordEnvelope((string) Str::uuid(), new ActivitySubjectReference('record', 'one'), null, 'reviewed', 'audit', CarbonImmutable::now(), ['consumer_key' => null]);
    expect(ActivityRecordEnvelope::fromArray($envelope->toArray())->toArray())->toBe($envelope->toArray());
});

test('configuration exception factories expose stable diagnostics and safe opt-in rendering', function (): void {
    $exceptions = [
        ActivityConfigurationException::emptyTableName(),
        ActivityConfigurationException::invalidConnectionName(),
        ActivityConfigurationException::nonEloquentActivityModel(),
        ActivityConfigurationException::invalidMappingModel('Mapping', 'NotAModel'),
        ActivityConfigurationException::emptyMappingLogName('Mapping'),
        ActivityConfigurationException::duplicateMapping('ReplacementMapping', 'App\\Models\\Order'),
    ];

    expect(array_map(static fn (ActivityConfigurationException $exception): string => $exception->responseCode(), $exceptions))
        ->toBe([
            ActivityResponseCode::InvalidConfiguration->value,
            ActivityResponseCode::InvalidConfiguration->value,
            ActivityResponseCode::InvalidConfiguration->value,
            ActivityResponseCode::InvalidMapping->value,
            ActivityResponseCode::InvalidMapping->value,
            ActivityResponseCode::InvalidMapping->value,
        ])
        ->and($exceptions[1]->context())->toBe(['configuration' => 'activity.storage.connection'])
        ->and($exceptions[3]->context())->toBe([
            'mapping_class' => 'Mapping',
            'model_class' => 'NotAModel',
        ]);

    $htmlRequest = Request::create('/activity');
    $jsonRequest = Request::create('/activity', server: ['HTTP_ACCEPT' => 'application/json']);
    $renderer = app(PackageExceptionRenderer::class);
    $jsonResponse = $renderer->render($exceptions[0], $jsonRequest);

    expect($renderer->render($exceptions[0], $htmlRequest))->toBeNull()
        ->and($jsonResponse)->not->toBeFalse()
        ->and($jsonResponse->getStatusCode())->toBe(500)
        ->and($jsonResponse->getData(true))->toBe([
            'message' => ActivityResponseCode::InvalidConfiguration->getMessage(),
            'code' => ActivityResponseCode::InvalidConfiguration->value,
            'context' => [],
        ]);
});
