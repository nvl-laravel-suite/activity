<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Nvl\Activity\Data\Display\ActivityCauser;
use Nvl\Activity\Data\Display\ActivityChangeDetail;
use Nvl\Activity\Data\Display\ActivityItem;
use Nvl\Activity\Data\Display\ActivityItemProperties;
use Nvl\Activity\Enums\EntrySource;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Support\CauserNormalizer;
use Spatie\Activitylog\Models\Activity;
use Stringable;

/**
 * Normalizes single Spatie activity rows into the Activity frontend contract.
 */
final class ActivityEntryNormalizer
{
    /**
     * Create the entry normalizer with headline, label, causer, and diff capabilities.
     */
    public function __construct(
        private readonly HeadlineRenderer $headlineRenderer,
        private readonly LabelResolver $labelResolver,
        private readonly CauserNormalizer $causerNormalizer,
        private readonly ActivityDiffBuilder $activityDiffBuilder,
    ) {}

    /**
     * Normalize a single Activity model to a frontend-friendly structure.
     */
    public function normalize(Activity $activity): ActivityItem
    {
        $propsRaw = $activity->properties;
        /** @var array<string, mixed> $properties */
        $properties = $propsRaw instanceof Arrayable ? $propsRaw->toArray() : (array) $propsRaw;

        // Spatie v5: tracked changes live in attribute_changes, merge them for diff building
        $attributeChangesRaw = $activity->getAttribute('attribute_changes');
        if ($attributeChangesRaw !== null) {
            /** @var array<string, mixed> $attributeChanges */
            $attributeChanges = $attributeChangesRaw instanceof Arrayable
                ? $attributeChangesRaw->toArray()
                : (array) $attributeChangesRaw;

            $properties = array_merge($properties, $attributeChanges);
        }

        $changeDetails = $this->activityDiffBuilder->build($activity, $properties);
        $formattedChanges = $changeDetails
            ->map(static fn (ActivityChangeDetail $detail): string => $detail->description)
            ->all();

        $firstChangedLabel = $changeDetails->first()?->label;
        $causer = ActivityCauser::from($this->causerNormalizer->normalize($activity->causer, $properties));
        $event = $this->resolveEventKey($activity, $properties);
        $eventLabel = $this->labelResolver->resolveEventLabel($event, $activity);
        $description = $this->stringValue(
            $properties['description_override'] ?? $activity->description,
        );
        $createdAtIso = $activity->created_at?->toISOString();
        $createdAtHuman = $activity->created_at?->diffForHumans();
        $actorName = $causer->name
            ?? ($causer->id !== null
                ? (string) $causer->id
                : (string) trans('activity::activity/general.actors.system'));
        $resolvedHeadline = $this->headlineRenderer->resolveHeadline(
            event: $event,
            activity: $activity,
            actorName: $actorName,
            causerId: $causer->id,
            changeDetails: $changeDetails,
        );
        $summary = $this->headlineRenderer->buildSummary($changeDetails->count(), $firstChangedLabel);

        return new ActivityItem(
            id: $this->stringValue($activity->getKey()),
            log: $this->stringValue($activity->log_name),
            event: $event,
            source: EntrySource::ActivityLog,
            eventLabel: $eventLabel,
            description: $description,
            createdAt: $createdAtIso,
            createdAtHuman: $createdAtHuman,
            causer: $causer,
            subjectType: $this->stringValue($activity->subject_type),
            subjectId: $activity->subject_id,
            subjectLabel: $this->labelResolver->resolveSubjectLabel($activity),
            headline: $resolvedHeadline->headline,
            headlineSegments: $resolvedHeadline->segments,
            summary: $summary,
            changes: $formattedChanges,
            changesDetailed: $changeDetails->all(),
            properties: ActivityItemProperties::fromPayload($properties),
        );
    }

    /**
     * Normalize a collection of Activity rows to frontend arrays.
     *
     * @param  Collection<int, ActivityLog>  $activities
     * @return array<int, ActivityItem>
     */
    public function normalizeCollection(Collection $activities): array
    {
        /** @var array<int, ActivityItem> */
        return $activities
            ->map(fn (ActivityLog $activity): ActivityItem => $this->normalize($activity))
            ->values()
            ->all();
    }

    /**
     * Resolve the stored event name with a structural update fallback.
     *
     * @param  array<string, mixed>  $properties
     */
    private function resolveEventKey(Activity $activity, array $properties): string
    {
        $event = $this->stringValue($activity->event);
        if ($event !== '') {
            return $event;
        }

        $attributeChangesRaw = $activity->getAttribute('attribute_changes');
        /** @var array<string, mixed> $attributeChanges */
        $attributeChanges = $attributeChangesRaw instanceof Arrayable
            ? $attributeChangesRaw->toArray()
            : (array) ($attributeChangesRaw ?? []);

        $changes = (array) ($properties['attributes'] ?? $attributeChanges['attributes'] ?? $properties['new'] ?? []);
        $oldValues = (array) ($properties['old'] ?? $attributeChanges['old'] ?? []);
        if ($changes !== [] || $oldValues !== []) {
            return 'updated';
        }

        return 'activity_logged';
    }

    /**
     * Normalize a scalar or stringable model value for display.
     */
    private function stringValue(mixed $value): string
    {
        return is_scalar($value) || $value instanceof Stringable ? (string) $value : '';
    }
}
