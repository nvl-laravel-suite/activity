<?php

declare(strict_types=1);

namespace Nvl\Activity\Data\Display;

use Illuminate\Database\Eloquent\Model;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Stringable;

/**
 * Activity-owned suggestion contract for historical user causers.
 *
 * Keeps the Activity causer endpoint independent from Auth module selector DTOs
 * while still reading users as the historical causer source.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class ActivityCauserSuggestion extends Data
{
    use DataTransform;

    /**
     * Create a suggestion payload for an activity causer.
     *
     * @param  string  $id  Normalized causer identifier
     * @param  string  $label  Primary causer display label
     * @param  string|Optional|null  $sublabel  Secondary causer label, usually email
     * @param  string|Optional|null  $type  Optional user type key
     */
    public function __construct(
        #[LiteralTypeScriptType('string')]
        public readonly string $id,
        #[LiteralTypeScriptType('string')]
        public readonly string $label,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $sublabel = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $type = new Optional,
    ) {}

    /**
     * Create an activity causer suggestion from a model.
     *
     * @param  Model  $user  Historical activity causer
     * @return self Suggestion data populated from the user model
     */
    public static function fromModel(Model $user): self
    {
        $key = $user->getKey();
        $id = is_string($key) || is_int($key) ? (string) $key : '';
        $label = self::stringAttribute(
            $user,
            config('activity.causer_suggestions.label_attribute', 'name'),
        );
        $sublabel = self::stringAttribute(
            $user,
            config('activity.causer_suggestions.sublabel_attribute', 'email'),
        );
        $type = self::stringAttribute(
            $user,
            config('activity.causer_suggestions.type_attribute', 'type'),
        );

        return new self(
            id: $id,
            label: $label ?? $id,
            sublabel: $sublabel,
            type: $type,
        );
    }

    /**
     * Read a configured scalar model attribute as a non-empty string.
     */
    private static function stringAttribute(Model $model, mixed $attribute): ?string
    {
        if (! is_string($attribute) || $attribute === '') {
            return null;
        }

        $value = $model->getAttribute($attribute);

        if (! is_scalar($value) && ! $value instanceof Stringable) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
