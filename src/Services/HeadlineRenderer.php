<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Illuminate\Support\Collection;
use Nvl\Activity\Data\Display\ActivityChangeDetail;
use Nvl\Activity\Data\Display\HeadlineSegment;
use Nvl\Activity\Enums\HeadlineSegmentType;
use Nvl\Activity\Support\ResolvedHeadline;
use Spatie\Activitylog\Models\Activity;

/**
 * Builds natural-language headlines and summaries for activity entries.
 *
 * Actor-first patterns: "Nicolas changed the status to expired."
 */
final class HeadlineRenderer
{
    private const string TRANS_PREFIX = 'activity::activity/general';

    /**
     * Create the headline renderer with model-aware label resolution.
     */
    public function __construct(
        private readonly LabelResolver $labelResolver,
    ) {}

    /**
     * Build a natural, actor-first headline for the activity.
     *
     * @param  string  $event  Event name
     * @param  Activity  $activity  Activity instance
     * @param  string  $actorName  Actor display name
     * @param  string|int|null  $causerId  Actor identifier for semantic rendering
     * @param  Collection<int, ActivityChangeDetail>  $changeDetails  Filtered change details
     * @return ResolvedHeadline Canonical headline result
     */
    public function resolveHeadline(
        string $event,
        Activity $activity,
        string $actorName,
        string|int|null $causerId,
        Collection $changeDetails,
    ): ResolvedHeadline {
        $entityType = mb_strtolower($this->labelResolver->resolveEntityTypeLabel($activity));
        $basePlaceholders = $this->basePlaceholders(
            actorName: $actorName,
            causerId: $causerId,
            entityType: $entityType,
        );

        [$template, $placeholders] = $this->resolveTemplate(
            event: $event,
            activity: $activity,
            changeDetails: $changeDetails,
            basePlaceholders: $basePlaceholders,
        );

        return new ResolvedHeadline(
            headline: $this->renderTemplate($template, $placeholders),
            segments: $this->buildSegmentsFromTemplate($template, $placeholders),
        );
    }

    /**
     * Build activity summary for update events.
     *
     * @param  int  $changeCount  Number of changed attributes
     * @param  string|null  $firstAttributeLabel  First changed attribute label
     * @return string Summary text or empty string
     */
    public function buildSummary(int $changeCount, ?string $firstAttributeLabel): string
    {
        if ($changeCount === 0) {
            return '';
        }

        if ($changeCount === 1 && $firstAttributeLabel !== null && $firstAttributeLabel !== '') {
            return (string) trans(self::TRANS_PREFIX.'.summary.single_attribute', [
                'attribute' => $firstAttributeLabel,
            ]);
        }

        return (string) trans(self::TRANS_PREFIX.'.summary.multiple_attributes', [
            'count' => $changeCount,
        ]);
    }

    /**
     * Build human-readable change description.
     *
     * @param  string  $attribute  Attribute label
     * @param  string|null  $old  Old value (formatted)
     * @param  string|null  $new  New value (formatted)
     * @return string Change description
     */
    public function buildChangedText(string $attribute, ?string $old, ?string $new): string
    {
        $attributeLabel = $attribute !== ''
            ? $attribute
            : (string) trans(self::TRANS_PREFIX.'.changes.unknown_attribute');

        $emptyValue = (string) trans(self::TRANS_PREFIX.'.changes.empty_value');

        $oldValue = $old ?? $emptyValue;
        $newValue = $new ?? $emptyValue;

        if ($old !== null && $new !== null) {
            return (string) trans(self::TRANS_PREFIX.'.changes.from_to', [
                'attribute' => $attributeLabel,
                'old' => $oldValue,
                'new' => $newValue,
            ]);
        }

        if ($new !== null) {
            return (string) trans(self::TRANS_PREFIX.'.changes.to_only', [
                'attribute' => $attributeLabel,
                'new' => $newValue,
            ]);
        }

        if ($old !== null) {
            return (string) trans(self::TRANS_PREFIX.'.changes.from_only', [
                'attribute' => $attributeLabel,
                'old' => $oldValue,
            ]);
        }

        return (string) trans(self::TRANS_PREFIX.'.summary.single_attribute', [
            'attribute' => $attributeLabel,
        ]);
    }

    /**
     * @return array<string, array{type: string, text: string, causerId?: string|int|null}>
     */
    private function basePlaceholders(
        string $actorName,
        string|int|null $causerId,
        string $entityType,
    ): array {
        return [
            'actor' => [
                'type' => 'actor',
                'text' => $actorName,
                'causerId' => $causerId,
            ],
            'subject' => [
                'type' => 'text',
                'text' => $entityType,
            ],
        ];
    }

    /**
     * @param  Collection<int, ActivityChangeDetail>  $changeDetails
     * @param  array<string, array{type: string, text: string, causerId?: string|int|null}>  $basePlaceholders
     * @return array{0: string, 1: array<string, array{type: string, text: string, causerId?: string|int|null}>}
     */
    private function resolveTemplate(
        string $event,
        Activity $activity,
        Collection $changeDetails,
        array $basePlaceholders,
    ): array {
        if (in_array($event, ['updated', 'details_updated'], true)) {
            return $this->resolveUpdatedTemplate($changeDetails, $basePlaceholders);
        }

        $status = $this->resolveStatusTemplate($event, $activity, $basePlaceholders);
        if ($status !== null) {
            return $status;
        }

        $shared = $this->resolveSharedEventTemplate($event, $activity, $basePlaceholders);
        if ($shared !== null) {
            return $shared;
        }

        $moduleTemplate = $this->resolveModuleEventTemplate($event, $activity, $basePlaceholders);
        if ($moduleTemplate !== null) {
            return $moduleTemplate;
        }

        return $this->resolveFallbackTemplate($event, $activity, $basePlaceholders);
    }

    /**
     * @param  Collection<int, ActivityChangeDetail>  $changeDetails
     * @param  array<string, array{type: string, text: string, causerId?: string|int|null}>  $basePlaceholders
     * @return array{0: string, 1: array<string, array{type: string, text: string, causerId?: string|int|null}>}
     */
    private function resolveUpdatedTemplate(Collection $changeDetails, array $basePlaceholders): array
    {
        $changeCount = $changeDetails->count();

        if ($changeCount === 1) {
            $first = $changeDetails->first();

            if (! $first instanceof ActivityChangeDetail) {
                return [
                    (string) trans(self::TRANS_PREFIX.'.templates.updated'),
                    $basePlaceholders,
                ];
            }

            $fieldLabel = $first->label;
            $newValue = $first->new ?? '';

            if ($fieldLabel !== '' && $newValue !== '') {
                return [
                    (string) trans(self::TRANS_PREFIX.'.templates.updated_field_value'),
                    [
                        ...$basePlaceholders,
                        'field' => ['type' => 'field', 'text' => (string) $fieldLabel],
                        'value' => ['type' => 'value', 'text' => (string) $newValue],
                    ],
                ];
            }

            if ($fieldLabel !== '') {
                return [
                    (string) trans(self::TRANS_PREFIX.'.templates.updated_field'),
                    [
                        ...$basePlaceholders,
                        'field' => ['type' => 'field', 'text' => (string) $fieldLabel],
                    ],
                ];
            }
        } elseif ($changeCount > 1) {
            $fieldList = $changeDetails
                ->map(static fn (ActivityChangeDetail $detail): string => $detail->label)
                ->filter()
                ->implode(', ');
            if ($fieldList !== '') {
                return [
                    (string) trans(self::TRANS_PREFIX.'.templates.updated_fields'),
                    [
                        ...$basePlaceholders,
                        'fields' => ['type' => 'field', 'text' => $fieldList],
                    ],
                ];
            }
        }

        return [
            (string) trans(self::TRANS_PREFIX.'.templates.updated'),
            $basePlaceholders,
        ];
    }

    /**
     * @param  array<string, array{type: string, text: string, causerId?: string|int|null}>  $basePlaceholders
     * @return array{0: string, 1: array<string, array{type: string, text: string, causerId?: string|int|null}>}|null
     */
    private function resolveStatusTemplate(
        string $event,
        Activity $activity,
        array $basePlaceholders,
    ): ?array {
        if (! in_array($event, ['status_changed', 'status_transition', 'status_override'], true)) {
            return null;
        }

        $statusLabel = $this->labelResolver->resolveNewStatusLabel($activity);
        if ($statusLabel === null || $statusLabel === '') {
            return null;
        }

        $eventSpecificTemplateKey = self::TRANS_PREFIX.".templates.{$event}_to";
        $template = trans($eventSpecificTemplateKey);

        if ($template === $eventSpecificTemplateKey) {
            $template = trans(self::TRANS_PREFIX.'.templates.status_changed_to');
        }

        return [
            (string) $template,
            [
                ...$basePlaceholders,
                'status' => ['type' => 'status', 'text' => $statusLabel],
            ],
        ];
    }

    /**
     * @param  array<string, array{type: string, text: string, causerId?: string|int|null}>  $basePlaceholders
     * @return array{0: string, 1: array<string, array{type: string, text: string, causerId?: string|int|null}>}|null
     */
    private function resolveSharedEventTemplate(
        string $event,
        Activity $activity,
        array $basePlaceholders,
    ): ?array {
        $templateKey = self::TRANS_PREFIX.".templates.{$event}";
        $template = trans($templateKey);

        if ($template === $templateKey) {
            return null;
        }

        $placeholders = $this->withEventPlaceholders($event, $activity, $basePlaceholders);

        return $this->canRenderTemplate((string) $template, $placeholders)
            ? [(string) $template, $placeholders]
            : null;
    }

    /**
     * @param  array<string, array{type: string, text: string, causerId?: string|int|null}>  $basePlaceholders
     * @return array{0: string, 1: array<string, array{type: string, text: string, causerId?: string|int|null}>}|null
     */
    private function resolveModuleEventTemplate(
        string $event,
        Activity $activity,
        array $basePlaceholders,
    ): ?array {
        $template = $this->labelResolver->resolveModuleEventTemplate($event, $activity);
        if (! is_string($template) || $template === '') {
            return null;
        }

        $placeholders = $this->withEventPlaceholders($event, $activity, $basePlaceholders);

        return $this->canRenderTemplate($template, $placeholders)
            ? [$template, $placeholders]
            : null;
    }

    /**
     * @param  array<string, array{type: string, text: string, causerId?: string|int|null}>  $basePlaceholders
     * @return array{0: string, 1: array<string, array{type: string, text: string, causerId?: string|int|null}>}
     */
    private function resolveFallbackTemplate(
        string $event,
        Activity $activity,
        array $basePlaceholders,
    ): array {
        return [
            (string) trans(self::TRANS_PREFIX.'.headline'),
            [
                ...$basePlaceholders,
                'event' => [
                    'type' => 'text',
                    'text' => $this->labelResolver->resolveEventLabel($event, $activity),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, array{type: string, text: string, causerId?: string|int|null}>  $basePlaceholders
     * @return array<string, array{type: string, text: string, causerId?: string|int|null}>
     */
    private function withEventPlaceholders(
        string $event,
        Activity $activity,
        array $basePlaceholders,
    ): array {
        $placeholders = [
            ...$basePlaceholders,
            ...$this->labelResolver->resolveEventHeadlinePlaceholders($event, $activity),
        ];

        if (isset($placeholders['value'])) {
            return $placeholders;
        }

        $eventValue = $this->labelResolver->resolveEventDisplayValue($event, $activity);

        if (! is_string($eventValue) || trim($eventValue) === '') {
            return $placeholders;
        }

        return [
            ...$placeholders,
            'value' => [
                'type' => 'value',
                'text' => trim($eventValue),
            ],
        ];
    }

    /**
     * @param  array<string, array{type: string, text: string, causerId?: string|int|null}>  $placeholders
     */
    private function renderTemplate(string $template, array $placeholders): string
    {
        $replacements = [];

        foreach ($placeholders as $key => $payload) {
            $replacements[':'.$key] = $payload['text'];
        }

        return strtr($template, $replacements);
    }

    /**
     * @param  array<string, array{type: string, text: string, causerId?: string|int|null}>  $placeholders
     * @return array<int, HeadlineSegment>
     */
    private function buildSegmentsFromTemplate(string $template, array $placeholders): array
    {
        $rawSegments = [];

        foreach ($this->splitTemplateIntoParts($template) as $part) {
            if (str_starts_with($part, ':')) {
                $placeholderKey = mb_substr($part, 1);
                $payload = $placeholders[$placeholderKey] ?? null;

                if (! is_array($payload)) {
                    $rawSegments[] = new HeadlineSegment(type: HeadlineSegmentType::Text, text: $part);

                    continue;
                }

                $text = trim($payload['text']);
                if ($text === '') {
                    continue;
                }

                $rawSegments[] = new HeadlineSegment(
                    type: HeadlineSegmentType::tryFrom((string) $payload['type'])
                        ?? HeadlineSegmentType::Text,
                    text: (string) $payload['text'],
                    causerId: $payload['causerId'] ?? null,
                );

                continue;
            }

            if ($part !== '') {
                $rawSegments[] = new HeadlineSegment(type: HeadlineSegmentType::Text, text: $part);
            }
        }

        return $this->mergeAdjacentTextSegments($rawSegments);
    }

    /**
     * @param  array<int, HeadlineSegment>  $segments
     * @return array<int, HeadlineSegment>
     */
    private function mergeAdjacentTextSegments(array $segments): array
    {
        $merged = [];

        foreach ($segments as $segment) {
            $lastIndex = array_key_last($merged);
            $last = $lastIndex !== null ? $merged[$lastIndex] : null;

            if ($last instanceof HeadlineSegment
                && $last->type === HeadlineSegmentType::Text
                && $segment->type === HeadlineSegmentType::Text) {
                $merged[$lastIndex] = new HeadlineSegment(
                    type: HeadlineSegmentType::Text,
                    text: $last->text.$segment->text,
                );

                continue;
            }

            $merged[] = $segment;
        }

        return $merged;
    }

    /**
     * @param  array<string, array{type: string, text: string, causerId?: string|int|null}>  $placeholders
     */
    private function canRenderTemplate(string $template, array $placeholders): bool
    {
        foreach ($this->extractPlaceholderNames($template) as $placeholderName) {
            $payload = $placeholders[$placeholderName] ?? null;

            if (! is_array($payload)) {
                return false;
            }

            $text = trim($payload['text']);
            if ($text === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function splitTemplateIntoParts(string $template): array
    {
        return preg_split('/(:[a-zA-Z_]+)/', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$template];
    }

    /**
     * @return array<int, string>
     */
    private function extractPlaceholderNames(string $template): array
    {
        $matches = [];
        preg_match_all('/:([a-zA-Z_]+)/', $template, $matches);

        return array_values(array_unique($matches[1]));
    }
}
