<?php

declare(strict_types=1);

namespace Nvl\Activity\Data\Display;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Typed read contract for stable metadata attached to an activity timeline item.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class ActivityItemProperties extends Data
{
    use DataTransform;

    /**
     * Raw activity payload keys represented by explicit DTO properties.
     *
     * @var array<int, string>
     */
    private const RAW_PAYLOAD_KEYS = [
        'resource_type',
        'resource_id',
        'status',
        'status_label',
        'subject',
        'comment',
        'attributes',
        'old',
        'new',
        'context',
        'attribute_changes',
        'from_status',
        'to_status',
        'new_status',
        'source',
        'visibility',
        'importance',
        'description_override',
    ];

    /**
     * Create a typed properties contract from stored or merged timeline metadata.
     *
     * @param  array<string, mixed>  $payload  Raw activity properties JSON using storage/provider key names
     */
    public static function fromPayload(array $payload): self
    {
        $extra = self::extraPayload($payload);

        return new self(
            resourceType: self::optionalString($payload, 'resource_type'),
            resourceId: self::optionalIdentifier($payload, 'resource_id'),
            status: self::optionalString($payload, 'status'),
            statusLabel: self::optionalString($payload, 'status_label'),
            subject: self::optionalString($payload, 'subject'),
            comment: self::optionalRecord($payload, 'comment'),
            attributes: self::optionalRecord($payload, 'attributes'),
            old: self::optionalRecord($payload, 'old'),
            new: self::optionalRecord($payload, 'new'),
            context: self::optionalRecord($payload, 'context'),
            attributeChanges: self::optionalRecord($payload, 'attribute_changes'),
            fromStatus: self::optionalString($payload, 'from_status'),
            toStatus: self::optionalString($payload, 'to_status'),
            newStatus: self::optionalString($payload, 'new_status'),
            source: self::optionalString($payload, 'source'),
            visibility: self::optionalString($payload, 'visibility'),
            importance: self::optionalString($payload, 'importance'),
            descriptionOverride: self::optionalString($payload, 'description_override'),
            extra: $extra === [] ? Optional::create() : $extra,
        );
    }

    /**
     * Create a typed properties payload.
     *
     * @param  string|Optional|null  $resourceType  Source model or resource type
     * @param  string|int|Optional|null  $resourceId  Source resource identifier
     * @param  string|Optional|null  $status  Source status key
     * @param  string|Optional|null  $statusLabel  Human-readable source status
     * @param  string|Optional|null  $subject  Mail or external source subject
     * @param  array<string, mixed>|Optional|null  $comment  Merged comment display payload
     * @param  array<string, mixed>|Optional|null  $attributes  New model values for diff-style activity rows
     * @param  array<string, mixed>|Optional|null  $old  Old model values for diff-style activity rows
     * @param  array<string, mixed>|Optional|null  $new  Alternative new-value payload used by audit providers
     * @param  array<string, mixed>|Optional|null  $context  Event-specific context metadata
     * @param  array<string, mixed>|Optional|null  $attributeChanges  Spatie attribute_changes payload
     * @param  string|Optional|null  $fromStatus  Source status before a transition
     * @param  string|Optional|null  $toStatus  Source status after a transition
     * @param  string|Optional|null  $newStatus  Alternative target status helper
     * @param  string|Optional|null  $source  Backend source metadata
     * @param  string|Optional|null  $visibility  Activity visibility metadata
     * @param  string|Optional|null  $importance  Activity importance metadata
     * @param  string|Optional|null  $descriptionOverride  Stored headline override metadata
     * @param  array<string, mixed>|Optional  $extra  Event-specific metadata without stable top-level semantics
     */
    public function __construct(
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $resourceType = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number | string | null')]
        public readonly string|int|Optional|null $resourceId = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $status = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $statusLabel = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $subject = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Nvl.Activity.Data.Display.ActivityCommentPayload | null')]
        public readonly array|Optional|null $comment = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public readonly array|Optional|null $attributes = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public readonly array|Optional|null $old = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public readonly array|Optional|null $new = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public readonly array|Optional|null $context = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public readonly array|Optional|null $attributeChanges = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $fromStatus = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $toStatus = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $newStatus = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Nvl.Activity.Enums.ActivitySource | null')]
        public readonly string|Optional|null $source = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Nvl.Activity.Enums.ActivityVisibility | null')]
        public readonly string|Optional|null $visibility = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Nvl.Activity.Enums.ActivityImportance | null')]
        public readonly string|Optional|null $importance = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $descriptionOverride = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array|Optional $extra = new Optional,
    ) {}

    /**
     * Return event-specific context metadata using the stored raw event keys.
     *
     * @return array<string, mixed>
     */
    public function contextArray(): array
    {
        return $this->arrayValue($this->context);
    }

    /**
     * Return normalized new-value activity diff metadata.
     *
     * @return array<string, mixed>
     */
    public function attributesArray(): array
    {
        return $this->arrayValue($this->attributes);
    }

    /**
     * Return normalized old-value activity diff metadata.
     *
     * @return array<string, mixed>
     */
    public function oldArray(): array
    {
        return $this->arrayValue($this->old);
    }

    /**
     * Return an optional array property as a plain array for read-side consumers.
     *
     * @param  array<string, mixed>|Optional|null  $value
     * @return array<string, mixed>
     */
    private function arrayValue(array|Optional|null $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return $value;
    }

    /**
     * Resolve a string metadata value from raw activity properties.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function optionalString(array $payload, string $key): string|Optional|null
    {
        $value = self::payloadValue($payload, $key);
        if ($value instanceof Optional || $value === null) {
            return $value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $stringValue = trim((string) $value);

        return $stringValue === '' ? null : $stringValue;
    }

    /**
     * Resolve a string or integer identifier from raw activity properties.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function optionalIdentifier(array $payload, string $key): string|int|Optional|null
    {
        $value = self::payloadValue($payload, $key);
        if ($value instanceof Optional || $value === null || is_int($value)) {
            return $value;
        }

        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $stringValue = trim((string) $value);

        return $stringValue === '' ? null : $stringValue;
    }

    /**
     * Resolve a string-keyed array metadata value from raw activity properties.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|Optional|null
     */
    private static function optionalRecord(array $payload, string $key): array|Optional|null
    {
        $value = self::payloadValue($payload, $key);
        if ($value instanceof Optional || $value === null) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> */
        return $value;
    }

    /**
     * Return the original value for a raw activity property key when present.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function payloadValue(array $payload, string $key): mixed
    {
        if (array_key_exists($key, $payload)) {
            return $payload[$key];
        }

        return Optional::create();
    }

    /**
     * Collect unknown properties as event-specific extras.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function extraPayload(array $payload): array
    {
        $knownKeys = array_fill_keys(self::RAW_PAYLOAD_KEYS, true);
        $extra = [];

        foreach ($payload as $key => $value) {
            if (isset($knownKeys[$key]) || $value instanceof Optional || $value === null) {
                continue;
            }

            $extra[$key] = $value;
        }

        return $extra;
    }
}
