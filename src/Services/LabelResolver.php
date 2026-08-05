<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Nvl\Activity\Contracts\ActivityMapping;
use Nvl\Activity\Contracts\ProvidesActivityHeadlinePlaceholders;
use Spatie\Activitylog\Models\Activity;
use Stringable;

/**
 * Resolves human-readable labels for fields, values, events, and entities.
 *
 * When an ActivityMapping is registered for the model, delegates to its typed
 * methods. Otherwise it falls back to stable package translations and headlines.
 */
final class LabelResolver
{
    /**
     * Create the label resolver with the host mapping registry.
     */
    public function __construct(
        private readonly MappingRegistry $mappingRegistry,
    ) {}

    /**
     * Resolve a display label for a field key.
     *
     * Lookup order: ActivityMapping -> Str::headline.
     *
     * @param  string  $field  Field name
     * @param  Activity  $activity  Activity instance for context
     * @return string Resolved field label
     */
    public function resolveFieldLabel(string $field, Activity $activity): string
    {
        $mapping = $this->mappingForActivity($activity);
        if ($mapping !== null) {
            return $mapping->fieldLabel($field);
        }

        return Str::headline($field);
    }

    /**
     * Resolve a display value for a field.
     *
     * Lookup order: ActivityMapping -> raw scalar.
     *
     * @param  string  $field  Field name
     * @param  mixed  $value  Raw value
     * @param  Activity  $activity  Activity instance for context
     * @return mixed Resolved value
     */
    public function resolveFieldValue(string $field, mixed $value, Activity $activity): mixed
    {
        if (! is_scalar($value)) {
            return $value;
        }

        $mapping = $this->mappingForActivity($activity);
        if ($mapping !== null) {
            return $mapping->fieldValue($field, $value);
        }

        return $value;
    }

    /**
     * Resolve event label from translations.
     *
     * Lookup order: package events -> Str::headline.
     *
     * @param  string  $event  Event name
     * @param  Activity  $activity  Activity instance for context
     * @return string Resolved event label
     */
    public function resolveEventLabel(string $event, Activity $activity): string
    {
        $activitiesKey = "activity::activity/general.events.{$event}";
        $label = trans($activitiesKey);
        if ($label !== $activitiesKey) {
            return (string) $label;
        }

        return Str::headline($event);
    }

    /**
     * Resolve the registered or generic entity type label for headlines.
     *
     * @param  Activity  $activity  Activity instance
     * @return string Entity type label
     */
    public function resolveEntityTypeLabel(Activity $activity): string
    {
        $mapping = $this->mappingForActivity($activity);
        if ($mapping !== null) {
            return $mapping->entityLabel();
        }

        $basename = class_basename((string) $activity->subject_type);

        return Str::headline($basename ?: 'Item');
    }

    /**
     * Resolve subject label (instance identifier, not type name).
     *
     * @param  Activity  $activity  Activity instance
     * @return string Resolved subject label
     */
    public function resolveSubjectLabel(Activity $activity): string
    {
        $subject = $this->safeSubject($activity);
        $mapping = $this->mappingForActivity($activity);

        if ($subject instanceof Model && $mapping !== null) {
            $label = trim($mapping->subjectLabel($subject));

            return $label !== '' ? $label : $mapping->entityLabel();
        }

        $basename = class_basename((string) $activity->subject_type);

        return Str::headline($basename ?: 'Item');
    }

    /**
     * Extract and resolve the new status label from a status-change activity.
     *
     * @param  Activity  $activity  Activity instance
     * @return string|null Resolved status label or null
     */
    public function resolveNewStatusLabel(Activity $activity): ?string
    {
        $props = $this->activityProperties($activity);
        $context = is_array($props['context'] ?? null) ? $props['context'] : [];

        $status = $props['new_status']
            ?? $props['to_status']
            ?? $context['new_status']
            ?? $context['to_status']
            ?? null;

        if ($status === null) {
            $attributes = (array) ($props['attributes'] ?? $props['new'] ?? []);
            $status = $attributes['status'] ?? null;
        }

        if (! is_scalar($status) && ! $status instanceof Stringable) {
            return null;
        }

        $statusStr = trim((string) $status);

        if ($statusStr === '') {
            return null;
        }

        $mapping = $this->mappingForActivity($activity);
        if ($mapping !== null) {
            return $mapping->fieldValue('status', $statusStr);
        }

        return Str::headline(str_replace('_', ' ', $statusStr));
    }

    /**
     * Extract a display value from activity properties for event-specific templates.
     *
     * @param  string  $event  Event name
     * @param  Activity  $activity  Activity instance
     * @return string|null Display value or null
     */
    public function resolveEventDisplayValue(string $event, Activity $activity): ?string
    {
        $mapping = $this->mappingForActivity($activity);
        if ($mapping === null) {
            return null;
        }

        return $mapping->eventDisplayValue($event, $this->activityProperties($activity));
    }

    /**
     * Resolve named semantic placeholders for a module-owned event headline.
     *
     * @return array<string, array{type: string, text: string, causerId?: string|int|null}>
     */
    public function resolveEventHeadlinePlaceholders(string $event, Activity $activity): array
    {
        $mapping = $this->mappingForActivity($activity);

        if (! $mapping instanceof ProvidesActivityHeadlinePlaceholders) {
            return [];
        }

        return $this->normalizeHeadlinePlaceholders(
            $mapping->eventHeadlinePlaceholders($event, $this->activityProperties($activity)),
        );
    }

    /**
     * Resolve a module-owned event headline template from ActivityMapping.
     *
     * @param  string  $event  Event name
     * @param  Activity  $activity  Activity instance
     * @return string|null Template string with :placeholders, or null
     */
    public function resolveModuleEventTemplate(string $event, Activity $activity): ?string
    {
        $mapping = $this->mappingForActivity($activity);
        if ($mapping === null) {
            return null;
        }

        $templates = $mapping->eventTemplates();

        return $templates[$event] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function activityProperties(Activity $activity): array
    {
        $propsRaw = $activity->properties;

        /** @var array<string, mixed> $props */
        $props = $propsRaw instanceof Arrayable ? $propsRaw->toArray() : (array) $propsRaw;

        return $props;
    }

    /**
     * @param  array<string, mixed>  $placeholders
     * @return array<string, array{type: string, text: string, causerId?: string|int|null}>
     */
    private function normalizeHeadlinePlaceholders(array $placeholders): array
    {
        $normalized = [];

        foreach ($placeholders as $key => $payload) {
            if (! is_array($payload)) {
                continue;
            }

            $rawText = $payload['text'] ?? null;

            if (! is_scalar($rawText) && ! $rawText instanceof Stringable) {
                continue;
            }

            $text = trim((string) $rawText);
            if ($text === '') {
                continue;
            }

            $rawType = $payload['type'] ?? 'text';
            $type = is_string($rawType) ? $rawType : 'text';
            if (! in_array($type, ['text', 'actor', 'field', 'value', 'status'], true)) {
                $type = 'text';
            }

            $normalized[$key] = [
                'type' => $type,
                'text' => $text,
            ];

            $causerId = $payload['causerId'] ?? null;
            if (is_string($causerId) || is_int($causerId) || $causerId === null) {
                $normalized[$key]['causerId'] = $causerId;
            }
        }

        return $normalized;
    }

    /**
     * Get the ActivityMapping for the activity's subject model, if registered.
     *
     * @param  Activity  $activity  Activity instance
     * @return ActivityMapping|null Mapping or null
     */
    private function mappingForActivity(Activity $activity): ?ActivityMapping
    {
        $storedSubjectType = (string) $activity->subject_type;
        $subjectType = Relation::getMorphedModel($storedSubjectType) ?? $storedSubjectType;

        if ($subjectType === '' || ! class_exists($subjectType)) {
            return null;
        }

        /** @var class-string $subjectType */
        return $this->mappingRegistry->forModel($subjectType);
    }

    /**
     * Safely access the activity subject without throwing.
     *
     * @param  Activity  $activity  Activity instance
     * @return object|null Subject model or null
     */
    private function safeSubject(Activity $activity): ?object
    {
        $subject = $activity->subject;

        return is_object($subject) ? $subject : null;
    }
}
