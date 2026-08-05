<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Nvl\Activity\Enums\ActivityImportance;
use Nvl\Activity\Enums\ActivitySource;
use Nvl\Activity\Enums\ActivityVisibility;
use Nvl\Activity\Exceptions\ActivityRecordingException;
use Nvl\Activity\Support\TimelineActivityRules;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;

/**
 * Writes canonical activity records with consistent metadata, source, and diffs.
 *
 * This service is the only shared writer behind the `ActivityLog` facade.
 * Semantic event factories delegate actual persistence here.
 */
final class ActivityRecorder
{
    /**
     * Record an activity with the canonical structured payload contract.
     *
     * @param  string|BackedEnum  $event  Canonical event key or backed enum
     * @param  array<string, mixed>  $context  Event-specific business context
     * @param  array<string, mixed>|null  $attributes  Explicit changed attribute values
     * @param  array<string, mixed>|null  $old  Explicit previous attribute values
     * @param  Model|string|int|null  $actor  Causing actor model, scalar ID, or null
     * @param  string|BackedEnum|null  $source  Entry source classification; inferred when omitted
     * @param  string|BackedEnum|null  $visibility  Timeline visibility classification; timeline by default
     * @param  string|BackedEnum|null  $importance  Importance classification; normal by default
     * @param  bool  $resolveChanges  Whether changed attributes may be inferred automatically
     * @param  string|null  $batchUuid  Optional caller-owned batch identifier
     * @return ActivityContract|null Persisted activity or null when the event key is blank
     */
    public function record(
        ?Model $subject,
        string|BackedEnum $event,
        string $description,
        array $context = [],
        ?array $attributes = null,
        ?array $old = null,
        Model|string|int|null $actor = null,
        ?string $logName = null,
        string|BackedEnum|null $source = null,
        string|BackedEnum|null $visibility = null,
        string|BackedEnum|null $importance = null,
        bool $resolveChanges = true,
        ?string $batchUuid = null,
    ): ?ActivityContract {
        $eventName = trim($this->normalizeValue($event));
        if ($eventName === '') {
            return null;
        }

        [$resolvedAttributes, $resolvedOld] = $this->resolveChangePayload(
            subject: $subject,
            event: $eventName,
            attributes: $attributes,
            old: $old,
            resolveChanges: $resolveChanges,
        );

        $normalizedLogName = trim($logName ?? '');
        $logger = $normalizedLogName !== ''
            ? activity($normalizedLogName)
            : activity();
        $logger->causedByAnonymous();

        $resolvedActor = is_string($actor) ? trim($actor) : $actor;
        $resolvedSource = $this->resolveSource($resolvedActor, $source);
        $resolvedVisibility = $this->resolveVisibility($visibility);
        $resolvedImportance = $this->resolveImportance($importance);

        $metadata = [
            'source' => $resolvedSource,
            'visibility' => $resolvedVisibility,
            'importance' => $resolvedImportance,
        ];

        if ($context !== []) {
            $metadata['context'] = $context;
        }

        if ($resolvedAttributes !== []) {
            $metadata['attributes'] = $resolvedAttributes;
        }

        if ($resolvedOld !== []) {
            $metadata['old'] = $resolvedOld;
        }

        $logger = $logger
            ->event($eventName)
            ->withProperties($metadata);

        $normalizedBatchUuid = trim($batchUuid ?? '');
        if ($normalizedBatchUuid !== '') {
            if (! Str::isUuid($normalizedBatchUuid)) {
                throw ActivityRecordingException::invalidBatchIdentifier();
            }

            $logger->tap(static function (ActivityContract $activity) use ($normalizedBatchUuid): void {
                if ($activity instanceof Model) {
                    $activity->setAttribute('batch_uuid', $normalizedBatchUuid);
                }
            });
        }

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        if ($resolvedActor instanceof Model) {
            $logger->causedBy($resolvedActor);
        } elseif ($resolvedActor !== null && $resolvedActor !== '') {
            $metadata['actor_id'] = (string) $resolvedActor;
            $logger->withProperties($metadata);
        }

        $normalizedDescription = trim($description);
        $message = $normalizedDescription !== '' ? $normalizedDescription : $eventName;

        return $logger->log($message);
    }

    /**
     * Normalize a scalar or backed enum to its persisted string value.
     *
     * @param  string|BackedEnum  $value  Enum or scalar string value
     * @return string Normalized persisted string value
     */
    private function normalizeValue(string|BackedEnum $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return trim($value);
    }

    /**
     * Resolve the persisted source classification for the activity record.
     *
     * @param  Model|string|int|null  $actor  Explicit causing actor
     * @param  string|BackedEnum|null  $source  Explicit source override
     * @return string Normalized source value
     */
    private function resolveSource(Model|string|int|null $actor, string|BackedEnum|null $source): string
    {
        if ($source !== null) {
            $normalizedSource = $this->normalizeValue($source);

            if ($normalizedSource !== '') {
                $resolvedSource = ActivitySource::tryFrom($normalizedSource);

                if (! $resolvedSource instanceof ActivitySource) {
                    throw ActivityRecordingException::invalidMetadata('source');
                }

                return $resolvedSource->value;
            }
        }

        return $actor === null || $actor === ''
            ? ActivitySource::System->value
            : ActivitySource::User->value;
    }

    /**
     * Resolve and validate the timeline visibility classification.
     */
    private function resolveVisibility(string|BackedEnum|null $visibility): string
    {
        $normalizedVisibility = $visibility === null
            ? ActivityVisibility::Timeline->value
            : $this->normalizeValue($visibility);

        if ($normalizedVisibility === '') {
            return ActivityVisibility::Timeline->value;
        }

        $resolvedVisibility = ActivityVisibility::tryFrom($normalizedVisibility);

        if (! $resolvedVisibility instanceof ActivityVisibility) {
            throw ActivityRecordingException::invalidMetadata('visibility');
        }

        return $resolvedVisibility->value;
    }

    /**
     * Resolve and validate the semantic importance classification.
     */
    private function resolveImportance(string|BackedEnum|null $importance): string
    {
        $normalizedImportance = $importance === null
            ? ActivityImportance::Normal->value
            : $this->normalizeValue($importance);

        if ($normalizedImportance === '') {
            return ActivityImportance::Normal->value;
        }

        $resolvedImportance = ActivityImportance::tryFrom($normalizedImportance);

        if (! $resolvedImportance instanceof ActivityImportance) {
            throw ActivityRecordingException::invalidMetadata('importance');
        }

        return $resolvedImportance->value;
    }

    /**
     * Resolve explicit or automatic change payload arrays for the activity entry.
     *
     * @param  Model|null  $subject  Mutated subject model when available
     * @param  string  $event  Normalized event key
     * @param  array<string, mixed>|null  $attributes  Explicit new values
     * @param  array<string, mixed>|null  $old  Explicit previous values
     * @param  bool  $resolveChanges  Whether automatic resolution is allowed
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function resolveChangePayload(
        ?Model $subject,
        string $event,
        ?array $attributes,
        ?array $old,
        bool $resolveChanges,
    ): array {
        if ($attributes !== null || $old !== null) {
            return [$attributes ?? [], $old ?? []];
        }

        if ($resolveChanges !== true || $subject === null || ! $this->shouldAutoResolveChanges($event)) {
            return [[], []];
        }

        $changes = [];
        foreach ($subject->getChanges() as $key => $value) {
            if (TimelineActivityRules::isNoisyChangeKey((string) $key)) {
                continue;
            }

            $changes[$key] = $value;
        }

        if ($changes === []) {
            return [[], []];
        }

        $previous = $subject->getPrevious();

        return [
            $changes,
            array_intersect_key($previous, $changes),
        ];
    }

    /**
     * Determine whether the event should automatically expose changed attributes.
     *
     * @param  string  $event  Normalized event key
     * @return bool True when automatic diff resolution is useful
     */
    private function shouldAutoResolveChanges(string $event): bool
    {
        return in_array($event, [
            'updated',
            'details_updated',
            'status_changed',
            'status_transition',
            'status_override',
        ], true);
    }
}
