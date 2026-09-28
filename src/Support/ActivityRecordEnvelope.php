<?php

declare(strict_types=1);

namespace Nvl\Activity\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/** Holds the immutable, JSON-safe facts needed to deliver one activity exactly once. */
final readonly class ActivityRecordEnvelope
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $attributes
     * @param  array<string, mixed>|null  $old
     */
    public function __construct(
        public string $id,
        public ActivitySubjectReference $subject,
        public ?ActivityCauserReference $causer,
        public string $event,
        public string $logName,
        public CarbonImmutable $occurredAt,
        public array $context = [],
        public ?array $attributes = null,
        public ?array $old = null,
        public ?string $scalarActorId = null,
    ) {
        if (! Str::isUuid($id) || $id !== trim($id)) {
            throw new InvalidArgumentException('Activity envelope IDs must be valid UUIDs.');
        }

        foreach (['event' => $event, 'logName' => $logName] as $field => $value) {
            if ($value === '' || $value !== trim($value) || str_contains($value, "\0") || mb_strlen($value) > 255) {
                throw new InvalidArgumentException("Activity envelope {$field} must contain 1 to 255 non-blank characters.");
            }
        }

        if ($causer !== null && $scalarActorId !== null) {
            throw new InvalidArgumentException('Activity envelopes cannot combine native and scalar causers.');
        }

        if ($scalarActorId !== null && ($scalarActorId === '' || $scalarActorId !== trim($scalarActorId)
            || str_contains($scalarActorId, "\0") || mb_strlen($scalarActorId) > 255)) {
            throw new InvalidArgumentException('Activity envelope scalar actor IDs must contain 1 to 255 non-blank characters.');
        }

        try {
            json_encode([$context, $attributes, $old], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Activity envelope metadata must be JSON serializable.', previous: $exception);
        }

        foreach (['context' => $context, 'attributes' => $attributes, 'old' => $old] as $field => $values) {
            if ($values !== null && ! self::isJsonValue($values, true)) {
                throw new InvalidArgumentException("Activity envelope {$field} must be a JSON-safe record.");
            }
        }
    }

    /**
     * Return the stable JSON-compatible payload for an outbox row.
     *
     * @return array{id: string, subject: array{type: string, id: string|int}, causer: array{type: string, id: string|int}|null, event: string, logName: string, occurredAt: string, context: array<string, mixed>, attributes: array<string, mixed>|null, old: array<string, mixed>|null, scalarActorId: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'subject' => ['type' => $this->subject->type, 'id' => $this->subject->id],
            'causer' => $this->causer === null ? null : ['type' => $this->causer->type, 'id' => $this->causer->id],
            'event' => $this->event,
            'logName' => $this->logName,
            'occurredAt' => $this->occurredAt->format('Y-m-d\TH:i:s.uP'),
            'context' => $this->context,
            'attributes' => $this->attributes,
            'old' => $this->old,
            'scalarActorId' => $this->scalarActorId,
        ];
    }

    /**
     * Restore an envelope only from its complete, validated wire shape.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $keys = ['id', 'subject', 'causer', 'event', 'logName', 'occurredAt', 'context', 'attributes', 'old', 'scalarActorId'];
        $payloadKeys = array_keys($payload);
        sort($keys);
        sort($payloadKeys);
        if ($payloadKeys !== $keys) {
            throw new InvalidArgumentException('Activity envelope payload keys are invalid.');
        }

        $subject = self::reference($payload['subject']);
        $causer = $payload['causer'] === null ? null : self::reference($payload['causer']);
        if (! is_string($payload['id']) || ! is_string($payload['event'])
            || ! is_string($payload['logName']) || ! is_string($payload['occurredAt'])
            || ($payload['scalarActorId'] !== null && ! is_string($payload['scalarActorId']))) {
            throw new InvalidArgumentException('Activity envelope payload types are invalid.');
        }

        $occurredAt = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $payload['occurredAt']);
        if (! $occurredAt instanceof CarbonImmutable
            || $occurredAt->format('Y-m-d\TH:i:s.uP') !== $payload['occurredAt']) {
            throw new InvalidArgumentException('Activity envelope occurrence time is invalid.');
        }

        $context = self::record($payload['context'], 'context');
        $attributes = $payload['attributes'] === null ? null : self::record($payload['attributes'], 'attributes');
        $old = $payload['old'] === null ? null : self::record($payload['old'], 'old');

        return new self(
            id: $payload['id'],
            subject: new ActivitySubjectReference($subject['type'], $subject['id']),
            causer: $causer === null ? null : new ActivityCauserReference($causer['type'], $causer['id']),
            event: $payload['event'],
            logName: $payload['logName'],
            occurredAt: $occurredAt,
            context: $context,
            attributes: $attributes,
            old: $old,
            scalarActorId: $payload['scalarActorId'],
        );
    }

    /** @return array{type: string, id: string|int} */
    private static function reference(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Activity envelope references must contain a type and scalar ID.');
        }

        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['id', 'type'] || ! is_string($value['type'])
            || (! is_string($value['id']) && ! is_int($value['id']))) {
            throw new InvalidArgumentException('Activity envelope references must contain a type and scalar ID.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private static function record(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("Activity envelope {$field} must be a JSON-safe record.");
        }

        $record = [];
        foreach ($value as $key => $item) {
            if (! is_string($key) || ! self::isJsonValue($item)) {
                throw new InvalidArgumentException("Activity envelope {$field} must be a JSON-safe record.");
            }

            $record[$key] = $item;
        }

        return $record;
    }

    /** Check that metadata contains only stable JSON values. */
    private static function isJsonValue(mixed $value, bool $record = false): bool
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return ! $record;
        }

        if (! is_array($value)) {
            return false;
        }

        if ($record && $value !== [] && array_is_list($value)) {
            return false;
        }

        foreach ($value as $key => $item) {
            if (($record && ! is_string($key)) || ! self::isJsonValue($item)) {
                return false;
            }
        }

        return true;
    }
}
