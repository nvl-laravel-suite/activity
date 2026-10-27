<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Activity\Enums\ActivityEvent;
use Nvl\Activity\Enums\ActivityImportance;
use Nvl\Activity\Enums\ActivitySource;
use Nvl\Activity\Enums\ActivityVisibility;
use Nvl\Activity\Exceptions\ActivityRecordingException;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Ownership\ActivityOwnershipGuard;
use Nvl\Activity\Support\ActivityMetadata;
use Nvl\Activity\Support\ActivityRecordEnvelope;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Activity\Support\TimelineActivityRules;
use Nvl\Support\Enums\SystemActor;

/**
 * Writes canonical activity records with consistent metadata, source, and diffs.
 *
 * Direct calls authorize an explicit principal; immutable envelopes deliver already-authorized facts.
 *
 * @api
 */
final class ActivityRecorder
{
    /** Capture ownership through the same guard used by automatic model logging. */
    public function __construct(
        private readonly ActivityOwnershipGuard $ownership,
        private readonly ActivityAccess $access,
    ) {}

    /** Deliver one immutable activity fact and return the same row on an identical retry. */
    public function recordEnvelope(ActivityRecordEnvelope $envelope): ActivityLog
    {
        $this->ownership->attributes();
        $existing = ActivityModelRegistry::query()->find($envelope->id);
        if ($existing instanceof ActivityLog) {
            return $this->assertMatchingEnvelope($existing, $envelope);
        }

        $properties = $this->envelopeProperties($envelope);
        $model = ActivityModelRegistry::newInstance();

        try {
            return $model->getConnection()->transaction(static function () use ($envelope, $properties): ActivityLog {
                return ActivityModelRegistry::query()->create([
                    'id' => $envelope->id,
                    'subject_type' => $envelope->subject->type,
                    'subject_id' => (string) $envelope->subject->id,
                    'causer_type' => $envelope->causer?->type,
                    'causer_id' => $envelope->causer === null ? null : (string) $envelope->causer->id,
                    'event' => $envelope->event,
                    'log_name' => $envelope->logName,
                    'description' => $envelope->event,
                    'properties' => $properties,
                    'created_at' => $envelope->occurredAt->utc(),
                    'updated_at' => $envelope->occurredAt->utc(),
                ]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = ActivityModelRegistry::query()->find($envelope->id);
            if (! $existing instanceof ActivityLog) {
                throw $exception;
            }

            return $this->assertMatchingEnvelope($existing, $envelope);
        }
    }

    /** @return array<string, mixed> */
    private function envelopeProperties(ActivityRecordEnvelope $envelope): array
    {
        $serialized = json_encode($this->canonicalEnvelopeValue($envelope->toArray()), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

        return [
            'source' => $envelope->causer !== null || $envelope->scalarActorId !== null
                ? ActivitySource::User->value : ActivitySource::System->value,
            'visibility' => ActivityVisibility::Timeline->value,
            'importance' => ActivityImportance::Normal->value,
            'context' => ActivityMetadata::redact($envelope->context),
            'attributes' => $envelope->attributes === null ? null : ActivityMetadata::redact($envelope->attributes),
            'old' => $envelope->old === null ? null : ActivityMetadata::redact($envelope->old),
            'actor_id' => $envelope->scalarActorId,
            'occurred_at' => $envelope->occurredAt->format('Y-m-d\TH:i:s.uP'),
            '_envelope_hash' => hash('sha256', $serialized),
        ];
    }

    /** Require an existing ID to represent the exact same immutable event. */
    private function assertMatchingEnvelope(ActivityLog $existing, ActivityRecordEnvelope $envelope): ActivityLog
    {
        $properties = $existing->properties?->toArray() ?? [];
        $expected = $this->envelopeProperties($envelope);
        $actualPayload = array_intersect_key($properties, $expected);

        if ($existing->subject_type !== $envelope->subject->type
            || (string) $existing->subject_id !== (string) $envelope->subject->id
            || $existing->causer_type !== $envelope->causer?->type
            || $existing->causer_id !== ($envelope->causer === null ? null : (string) $envelope->causer->id)
            || $existing->event !== $envelope->event
            || $existing->log_name !== $envelope->logName
            || $existing->description !== $envelope->event
            || $existing->created_at?->format('Y-m-d H:i:s') !== $envelope->occurredAt->utc()->format('Y-m-d H:i:s')
            || count($actualPayload) !== count($expected)
            || json_encode($this->canonicalEnvelopeValue($actualPayload), JSON_THROW_ON_ERROR)
                !== json_encode($this->canonicalEnvelopeValue($expected), JSON_THROW_ON_ERROR)) {
            throw ActivityRecordingException::conflictingEnvelope();
        }

        return $existing;
    }

    /** Keep JSON object key order from changing an envelope's immutable identity. */
    private function canonicalEnvelopeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalEnvelopeValue($item);
        }

        return $value;
    }

    /**
     * Record an authorized fact on Activity's connection, participating in an outer transaction.
     * Omitted diff arrays infer changes; explicit null suppresses that side of the diff.
     *
     * @param  array<string, mixed>  $entry  Validated against the documented entry allowlist.
     */
    public function record(array $entry, Authenticatable|SystemActor $actor): ActivityLog
    {
        $allowed = ['subject', 'event', 'description', 'context', 'attributes', 'old', 'log_name', 'visibility', 'importance', 'resolve_changes', 'batch_uuid'];
        if (array_diff(array_keys($entry), $allowed) !== []) {
            throw new InvalidArgumentException('Activity entries contain unknown or protected fields.');
        }
        $event = $entry['event'] ?? null;
        if ($event instanceof BackedEnum) {
            $event = $event->value;
        }
        if (! is_string($event) || trim($event) === '' || mb_strlen($event) > 255 || str_contains($event, "\0")) {
            throw new InvalidArgumentException('Activity events require 1 to 255 non-blank characters.');
        }
        $event = trim($event);
        $subject = $entry['subject'] ?? null;
        if ($subject !== null && ! $subject instanceof Model && ! $subject instanceof ActivitySubjectReference) {
            throw new InvalidArgumentException('Activity subjects must be persisted models or immutable references.');
        }
        if ($subject instanceof Model && (! $subject->exists || $subject->getKey() === null
            || $subject->getRawOriginal($subject->getKeyName()) !== $subject->getKey())) {
            throw new InvalidArgumentException('Activity model subjects must have persisted identities.');
        }
        $description = $this->text($entry['description'] ?? '', 65535);
        $logName = $this->text($entry['log_name'] ?? '', 255);
        foreach (['description', 'log_name'] as $key) {
            if (array_key_exists($key, $entry) && (! is_string($entry[$key]) || str_contains($entry[$key], "\0") || mb_strlen($entry[$key]) > ($key === 'description' ? 65535 : 255))) {
                throw new InvalidArgumentException('Invalid Activity '.$key.'.');
            }
        }
        if (array_key_exists('resolve_changes', $entry) && ! is_bool($entry['resolve_changes'])) {
            throw new InvalidArgumentException('Activity resolve_changes must be boolean.');
        }
        $context = ActivityMetadata::record($entry['context'] ?? [], 'context');
        $attributes = array_key_exists('attributes', $entry) ? ActivityMetadata::nullableRecord($entry['attributes'], 'attributes') : null;
        $old = array_key_exists('old', $entry) ? ActivityMetadata::nullableRecord($entry['old'], 'old') : null;
        $visibility = $this->classification($entry['visibility'] ?? ActivityVisibility::Timeline, ActivityVisibility::class);
        $importance = $this->classification($entry['importance'] ?? ActivityImportance::Normal, ActivityImportance::class);
        $batch = $entry['batch_uuid'] ?? null;
        if ($batch !== null && (! is_string($batch) || ! Str::isUuid($batch))) {
            throw ActivityRecordingException::invalidBatchIdentifier();
        }
        $this->access->authorize($actor, 'record', $subject);
        $this->ownership->attributes();
        if ($subject instanceof Model) {
            $this->ownership->assertSubject($subject);
        }
        [$attributes, $old] = $this->resolveChangePayload(
            $subject instanceof Model ? $subject : null, $event, $attributes, $old,
            ($entry['resolve_changes'] ?? true) && ! array_key_exists('attributes', $entry) && ! array_key_exists('old', $entry),
        );
        $properties = [
            'source' => $actor instanceof SystemActor ? ActivitySource::System->value : ActivitySource::User->value,
            'visibility' => $visibility, 'importance' => $importance,
            'context' => ActivityMetadata::redact($context),
            'attributes' => ActivityMetadata::redact($attributes), 'old' => ActivityMetadata::redact($old),
        ];
        $reference = $subject instanceof ActivitySubjectReference ? $subject : null;
        $causer = $actor instanceof Model ? $this->ownership->canonicalCauser($actor) : null;
        $causerId = $causer?->getKey();
        if ($causerId !== null && ! is_string($causerId) && ! is_int($causerId)) {
            throw new InvalidArgumentException('Activity causers require scalar identities.');
        }
        $model = ActivityModelRegistry::newInstance();

        return $model->getConnection()->transaction(static function () use ($description, $logName, $subject, $reference, $event, $causer, $causerId, $properties, $batch): ActivityLog {
            return ActivityModelRegistry::query()->create([
                'subject_type' => $subject instanceof Model ? $subject->getMorphClass() : $reference?->type,
                'subject_id' => $subject instanceof Model ? $subject->getKey() : $reference?->id,
                'causer_type' => $causer?->getMorphClass(), 'causer_id' => $causerId === null ? null : (string) $causerId,
                'event' => $event, 'description' => $description ?: $event,
                'log_name' => $logName ?: 'default',
                'properties' => $properties, 'batch_uuid' => $batch,
            ]);
        });
    }

    /** Validate optional descriptive text without converting caller objects. */
    private function text(mixed $value, int $limit): string
    {
        if (! is_string($value) || str_contains($value, "\0") || mb_strlen($value) > $limit) {
            throw new InvalidArgumentException('Invalid Activity descriptive text.');
        }

        return trim($value);
    }

    /** @param class-string<BackedEnum> $enum */
    private function classification(mixed $value, string $enum): string
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;
        if (! is_string($value) || $enum::tryFrom($value) === null) {
            throw new InvalidArgumentException('Invalid Activity classification.');
        }

        return $value;
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
            ActivityEvent::Updated->value,
            ActivityEvent::DetailsUpdated->value,
            ActivityEvent::StatusChanged->value,
            ActivityEvent::StatusTransition->value,
            ActivityEvent::StatusOverride->value,
        ], true);
    }
}
