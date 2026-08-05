<?php

declare(strict_types=1);

use Nvl\Activity\Data\Display\ActivityChangeDetail;
use Nvl\Activity\Enums\HeadlineSegmentType;
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

    expect($headline->headline)->toBe('Ada Lovelace changed the status to Pending Approval.')
        ->and($headline->segments)->toHaveCount(4)
        ->and($headline->segments[2]->type)->toBe(HeadlineSegmentType::Status)
        ->and($headline->segments[2]->text)->toBe('Pending Approval');
});

test('updated headlines expose semantic field and value segments', function (): void {
    $activity = headline_test_activity('Consumer\\Models\\Record', 'updated');
    $changes = collect([
        new ActivityChangeDetail(
            key: 'name',
            label: 'Name',
            old: 'Before',
            new: 'After',
            description: 'Name changed',
        ),
    ]);

    $headline = headline_test_renderer()->resolveHeadline(
        event: 'updated',
        activity: $activity,
        actorName: 'Grace Hopper',
        causerId: 42,
        changeDetails: $changes,
    );

    expect($headline->headline)->toBe('Grace Hopper changed Name to After.')
        ->and($headline->segments[2]->type)->toBe(HeadlineSegmentType::Field)
        ->and($headline->segments[4]->type)->toBe(HeadlineSegmentType::Value);
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

    expect($headline->headline)->toBe('Nicolas recorded Approval for this mapped entity.')
        ->and($headline->segments[2]->type)->toBe(HeadlineSegmentType::Value)
        ->and($headline->segments[2]->text)->toBe('Approval');
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

    expect($headline->headline)->toBe('Nicolas created this mapped entity.');
});
