<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\LabelResolver;
use Nvl\Activity\Services\MappingRegistry;
use Nvl\Activity\Tests\Stubs\TestActivityMapping;
use Nvl\Activity\Tests\Stubs\TestActivitySubjectWithHasModelActivity;

function make_activity_for_subject(object $subject): ActivityLog
{
    $activity = new ActivityLog;
    $activity->subject_type = $subject::class;
    $activity->event = 'updated';
    $activity->properties = [];
    $activity->setRelation('subject', $subject);

    return $activity;
}

test('label resolver ignores legacy hooks for subjects using has model activity when a mapping exists', function (): void {
    $subject = new TestActivitySubjectWithHasModelActivity;
    $subject->forceFill(['name' => 'Mapped subject name']);
    $mapping = new TestActivityMapping($subject::class);

    $registry = new MappingRegistry;
    $registry->register($mapping);

    $resolver = new LabelResolver($registry);
    $activity = make_activity_for_subject($subject);

    expect($resolver->resolveFieldLabel('status', $activity))->toBe('Mapped label')
        ->and($resolver->resolveFieldValue('status', 'draft', $activity))->toBe('Mapped value')
        ->and($resolver->resolveSubjectLabel($activity))->toBe('Mapped subject name');
});

test('label resolver falls back without invoking model-local compatibility hooks', function (): void {
    $subject = new class {};
    $resolver = new LabelResolver(new MappingRegistry);
    $activity = make_activity_for_subject($subject);

    expect($resolver->resolveFieldLabel('status', $activity))->toBe('Status')
        ->and($resolver->resolveFieldValue('status', 'draft', $activity))->toBe('draft');
});

test('label resolver resolves registered mappings through morph aliases', function (): void {
    $subject = new TestActivitySubjectWithHasModelActivity;
    $mapping = new TestActivityMapping($subject::class);
    $registry = new MappingRegistry;
    $registry->register($mapping);
    $originalMorphMap = Relation::morphMap() ?? [];

    try {
        Relation::morphMap(['mapped_subject' => $subject::class], merge: false);

        $activity = make_activity_for_subject($subject);
        $activity->subject_type = 'mapped_subject';

        expect((new LabelResolver($registry))->resolveFieldLabel('status', $activity))
            ->toBe('Mapped label');
    } finally {
        Relation::morphMap($originalMorphMap, merge: false);
    }
});
