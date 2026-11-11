<?php

declare(strict_types=1);

use Nvl\Activity\Enums\ActivityEvent;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\HeadlineRenderer;
use Nvl\Activity\Services\LabelResolver;
use Nvl\Activity\Services\MappingRegistry;
use Nvl\Activity\Tests\Stubs\TestActivityMapping;
use Nvl\Activity\Tests\Stubs\TestActivitySubjectWithHasModelActivity;

/**
 * @param  array<string, mixed>  $properties
 */
function headline_test_activity(
    string $subjectType,
    string $event,
    array $properties = [],
): ActivityLog {
    $activity = new ActivityLog;
    $activity->subject_type = $subjectType;
    $activity->event = $event;
    $activity->properties = $properties;

    return $activity;
}

function headline_test_renderer(?TestActivityMapping $mapping = null): HeadlineRenderer
{
    $registry = new MappingRegistry;

    if ($mapping !== null) {
        $registry->register($mapping);
    }

    return new HeadlineRenderer(new LabelResolver($registry));
}

test('status headlines resolve semantic target segments from structured properties', function (): void {
    $activity = headline_test_activity(
        subjectType: 'Consumer\\Models\\Record',
        event: 'status_transition',
        properties: [
            'context' => ['to_status' => 'pending_approval'],
        ],
    );

    $headline = headline_test_renderer()->resolveHeadline(
        event: 'status_transition',
        activity: $activity,
        actorName: 'Ada Lovelace',
        causerId: 'operator-1',
        changeDetails: collect(),
    );

    expect($headline)->toBe('Ada Lovelace changed the status to Pending Approval.');
});

test('updated headlines expose semantic field and value segments', function (): void {
    $activity = headline_test_activity('Consumer\\Models\\Record', 'updated');
    $changes = collect([
        ['key' => 'name', 'label' => 'Name', 'old' => 'Before', 'new' => 'After', 'description' => 'Name changed'],
    ]);

    $headline = headline_test_renderer()->resolveHeadline(
        event: 'updated',
        activity: $activity,
        actorName: 'Grace Hopper',
        causerId: 42,
        changeDetails: $changes,
    );

    expect($headline)->toBe('Grace Hopper changed Name to After.');
});

test('consumer mappings own semantic templates and display values', function (): void {
    $mapping = new TestActivityMapping(TestActivitySubjectWithHasModelActivity::class);
    $activity = headline_test_activity(
        subjectType: TestActivitySubjectWithHasModelActivity::class,
        event: 'consumer_event',
        properties: [
            'context' => ['value' => 'Approval'],
        ],
    );

    $headline = headline_test_renderer($mapping)->resolveHeadline(
        event: 'consumer_event',
        activity: $activity,
        actorName: 'Nicolas',
        causerId: 'operator-2',
        changeDetails: collect(),
    );

    expect($headline)->toBe('Nicolas recorded Approval for this mapped entity.');
});

test('shared templates remain canonical when a mapping declares the same event', function (): void {
    $mapping = new TestActivityMapping(
        TestActivitySubjectWithHasModelActivity::class,
        ['created' => ':actor used a consumer override.'],
    );
    $activity = headline_test_activity(
        subjectType: TestActivitySubjectWithHasModelActivity::class,
        event: 'created',
    );

    $headline = headline_test_renderer($mapping)->resolveHeadline(
        event: 'created',
        activity: $activity,
        actorName: 'Nicolas',
        causerId: 'operator-2',
        changeDetails: collect(),
    );

    expect($headline)->toBe('Nicolas created this mapped entity.');
});

test('package owned activity events render through shared headline templates', function (ActivityEvent $event): void {
    $activity = headline_test_activity(
        subjectType: 'Consumer\\Models\\Record',
        event: $event->value,
    );

    $headline = headline_test_renderer()->resolveHeadline(
        event: $event->value,
        activity: $activity,
        actorName: 'Nicolas',
        causerId: 'operator-2',
        changeDetails: collect(),
    );
    $templateEvent = $event === ActivityEvent::DetailsUpdated
        ? ActivityEvent::Updated
        : $event;
    $template = (string) trans("nvl-activity::activity/general.templates.{$templateEvent->value}");
    $expectedHeadline = strtr($template, [
        ':actor' => 'Nicolas',
        ':subject' => 'record',
    ]);

    expect($headline)->toBe($expectedHeadline);
})->with(collect(ActivityEvent::cases())->mapWithKeys(
    static fn (ActivityEvent $event): array => [$event->name => [$event]],
)->all());

test('headlines and change sentences stay bounded for very large diffs', function (): void {
    $renderer = headline_test_renderer();
    $activity = headline_test_activity(subjectType: 'Consumer\\Models\\Record', event: 'updated');
    $details = collect(range(1, 300))->map(static fn (int $index): array => [
        'key' => "field_{$index}", 'label' => "Field {$index}", 'old' => 'a', 'new' => 'b', 'description' => '',
    ]);
    $large = str_repeat('x', 60000);

    $manyFields = $renderer->resolveHeadline('updated', $activity, 'Ada', null, $details);
    $fewFields = $renderer->resolveHeadline('updated', $activity, 'Ada', null, $details->take(2));
    $largeValue = $renderer->resolveHeadline('updated', $activity, 'Ada', null, collect([
        ['key' => 'body', 'label' => 'Body', 'old' => null, 'new' => $large, 'description' => ''],
    ]));

    expect($manyFields)->toBe('Ada updated this record.')
        ->and($fewFields)->toBe('Ada updated Field 1, Field 2.')
        ->and(mb_strlen($largeValue))->toBeLessThan(300)
        ->and(mb_strlen($renderer->buildChangedText('Body', $large, $large.'y')))->toBeLessThan(600);
});
