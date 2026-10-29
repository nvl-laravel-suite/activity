<?php

declare(strict_types=1);

namespace Nvl\Activity\Support;

use InvalidArgumentException;

/**
 * Immutable event schema, attribution, audience and retention semantics.
 *
 * @api
 */
final readonly class ActivityEventPolicy
{
    /** @var list<string>|null */
    public ?array $subjectTypes;

    /** @var list<string>|null */
    public ?array $actorTypes;

    /** @var list<string>|null */
    public ?array $changeFields;

    /** @var list<string>|null */
    public ?array $metadataFields;

    /** @var list<string> */
    public array $redactedFields;

    /**
     * Null allowlists explicitly admit any bounded native value; empty lists admit none.
     * Null retention preserves facts forever. Actor policy describes attribution, never execution authority.
     *
     * @param  array<array-key,mixed>|null  $subjectTypes
     * @param  array<array-key,mixed>|null  $actorTypes
     * @param  array<array-key,mixed>|null  $changeFields
     * @param  array<array-key,mixed>|null  $metadataFields
     * @param  array<array-key,mixed>  $redactedFields
     */
    public function __construct(
        public string $event,
        ?array $subjectTypes = null,
        public string $actor = 'optional',
        ?array $actorTypes = null,
        ?array $changeFields = [],
        ?array $metadataFields = [],
        array $redactedFields = [],
        public ?int $retentionDays = 365,
        public string $audience = 'authorized',
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $event) !== 1 || strlen($event) > 255
            || ! in_array($actor, ['required', 'optional', 'system'], true)
            || ! in_array($audience, ['authorized', 'administrative', 'subject', 'actor', 'subject_or_actor'], true)
            || ($retentionDays !== null && ($retentionDays < 1 || $retentionDays > 36500))) {
            throw new InvalidArgumentException('Invalid native Activity event policy.');
        }
        $this->subjectTypes = self::names($subjectTypes);
        $this->actorTypes = self::names($actorTypes);
        $this->changeFields = self::names($changeFields);
        $this->metadataFields = self::names($metadataFields);
        $this->redactedFields = self::names($redactedFields) ?? [];
        if ($this->changeFields !== null && array_diff($this->redactedFields, $this->changeFields) !== []) {
            throw new InvalidArgumentException('Redacted Activity fields must be admitted change fields.');
        }
    }

    /** @param array<array-key,mixed>|null $values
     * @return list<string>|null
     */
    private static function names(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }
        if (! array_is_list($values) || count($values) > 100) {
            throw new InvalidArgumentException('Activity policy allowlists must be bounded name lists.');
        }
        $names = [];
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '' || strlen($value) > 255 || str_contains($value, "\0") || in_array($value, $names, true)) {
                throw new InvalidArgumentException('Activity policy allowlists require unique nonempty names.');
            }
            $names[] = $value;
        }

        return $names;
    }

    /**
     * Validate and redact normalized native facts before storage or capture mutation.
     *
     * @internal
     *
     * @param  array<string,mixed>  $attributes
     * @param  array<string,mixed>  $old
     * @param  array<string,mixed>  $context
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>}
     */
    public function prepare(?string $subjectType, ?string $actorType, bool $system, array $attributes, array $old, array $context): array
    {
        if (($this->subjectTypes !== null && ! in_array($subjectType, $this->subjectTypes, true))
            || ($this->actor === 'required' && $system) || ($this->actor === 'system' && ! $system)
            || (! $system && $this->actorTypes !== null && ! in_array($actorType, $this->actorTypes, true))) {
            throw new InvalidArgumentException('Activity attribution is not admitted by its event policy.');
        }
        foreach ([$attributes, $old] as $changes) {
            if ($this->changeFields !== null && array_diff(array_keys($changes), $this->changeFields) !== []) {
                throw new InvalidArgumentException('Activity changes contain fields outside the event policy.');
            }
        }
        if ($this->metadataFields !== null && array_diff(array_keys($context), $this->metadataFields) !== []) {
            throw new InvalidArgumentException('Activity metadata contains fields outside the event policy.');
        }
        foreach ($this->redactedFields as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = '[REDACTED]';
            }
            if (array_key_exists($field, $old)) {
                $old[$field] = '[REDACTED]';
            }
        }

        return [ActivityMetadata::redact($attributes), ActivityMetadata::redact($old), ActivityMetadata::redact($context)];
    }
}
