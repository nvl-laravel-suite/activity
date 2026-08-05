<?php

declare(strict_types=1);

namespace Nvl\Activity\Data\Display;

use Nvl\Activity\Enums\EntrySource;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Canonical typed representation of one normalized timeline row.
 *
 * Every host timeline source must eventually translate into this DTO so the
 * frontend can render mixed activity, comment, and mail rows from one contract.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class ActivityItem extends Data
{
    use DataTransform;

    /**
     * Clone the current item while changing only the rendered source classification.
     */
    public function withSource(EntrySource $source): self
    {
        return new self(
            id: $this->id,
            log: $this->log,
            event: $this->event,
            source: $source,
            eventLabel: $this->eventLabel,
            description: $this->description,
            createdAt: $this->createdAt,
            createdAtHuman: $this->createdAtHuman,
            causer: $this->causer,
            subjectType: $this->subjectType,
            subjectId: $this->subjectId,
            subjectLabel: $this->subjectLabel,
            headline: $this->headline,
            headlineSegments: $this->headlineSegments,
            summary: $this->summary,
            changes: $this->changes,
            changesDetailed: $this->changesDetailed,
            properties: $this->properties,
        );
    }

    /**
     * Create a canonical timeline row payload.
     *
     * @param  string  $id  Stable row identifier
     * @param  string  $log  Underlying activity log channel
     * @param  string  $event  Canonical event key
     * @param  EntrySource  $source  Timeline row source classification
     * @param  string|null  $eventLabel  Human-readable event label when resolved
     * @param  string  $description  Raw persisted activity description
     * @param  string|null  $createdAt  ISO-8601 timestamp
     * @param  string|null  $createdAtHuman  Humanized timestamp
     * @param  ActivityCauser|null  $causer  Normalized actor payload
     * @param  string|null  $subjectType  Subject model FQCN
     * @param  string|int|null  $subjectId  Subject primary key
     * @param  string|null  $subjectLabel  Human-readable subject label
     * @param  string|null  $headline  Primary display sentence
     * @param  array<int, HeadlineSegment>  $headlineSegments
     * @param  string|null  $summary  Optional compact summary for grouped contexts
     * @param  array<int, string>  $changes
     * @param  array<int, ActivityChangeDetail>  $changesDetailed
     * @param  ActivityItemProperties  $properties  Stable typed metadata for this row
     */
    public function __construct(
        #[LiteralTypeScriptType('string')]
        public readonly string $id,
        #[LiteralTypeScriptType('string')]
        public readonly string $log,
        #[LiteralTypeScriptType('string')]
        public readonly string $event,
        #[LiteralTypeScriptType("'activity_log' | 'mail' | 'comment'")]
        public readonly EntrySource $source = EntrySource::ActivityLog,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $eventLabel = null,
        #[LiteralTypeScriptType('string')]
        public readonly string $description = '',
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $createdAt = null,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $createdAtHuman = null,
        public readonly ?ActivityCauser $causer = null,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $subjectType = null,
        #[LiteralTypeScriptType('number | string | null')]
        public readonly string|int|null $subjectId = null,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $subjectLabel = null,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $headline = null,
        #[DataCollectionOf(HeadlineSegment::class)]
        public readonly array $headlineSegments = [],
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $summary = null,
        #[LiteralTypeScriptType('string[]')]
        public readonly array $changes = [],
        #[DataCollectionOf(ActivityChangeDetail::class)]
        public readonly array $changesDetailed = [],
        public readonly ActivityItemProperties $properties = new ActivityItemProperties,
    ) {}
}
