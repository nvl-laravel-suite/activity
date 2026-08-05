<?php

declare(strict_types=1);

namespace Nvl\Activity\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Extracts actor display data from Spatie's causer relation.
 */
final class CauserNormalizer
{
    /**
     * Normalize a causer model to display-friendly array.
     *
     * @param  Model|null  $causer  The causer model
     * @param  array<string, mixed>  $properties  Stored activity metadata
     * @return array{id: string|int|null, name: string|null, email: string|null}
     */
    public function normalize(?Model $causer, array $properties = []): array
    {
        if ($causer === null) {
            $actorId = $this->identifier($properties['actor_id'] ?? null);

            return [
                'id' => $actorId,
                'name' => null,
                'email' => null,
            ];
        }

        $labelAttribute = $this->configuredAttribute('label_attribute', 'name');
        $sublabelAttribute = $this->configuredAttribute('sublabel_attribute', 'email');
        $attributes = array_values(array_unique([
            $causer->getKeyName(),
            $labelAttribute,
            $sublabelAttribute,
            'first_name',
            'last_name',
            'name',
            'email',
        ]));
        /** @var array<string, mixed> $only */
        $only = $causer->only($attributes);

        $first = $this->string($only['first_name'] ?? null);
        $last = $this->string($only['last_name'] ?? null);
        $composed = trim(implode(' ', array_filter([$first, $last])));

        $configuredLabel = $this->string($only[$labelAttribute] ?? null);
        $displayName = $this->string($only['name'] ?? null);
        $name = $configuredLabel !== ''
            ? $configuredLabel
            : ($displayName !== '' ? $displayName : $composed);
        $configuredSublabel = $this->string($only[$sublabelAttribute] ?? null);
        $email = $configuredSublabel !== ''
            ? $configuredSublabel
            : $this->string($only['email'] ?? null);
        $identifier = $this->identifier($only[$causer->getKeyName()] ?? $causer->getKey());

        return [
            'id' => $identifier,
            'name' => $name !== '' ? $name : ($email !== '' ? $email : null),
            'email' => $email !== '' ? $email : null,
        ];
    }

    /**
     * Resolve one configured causer display attribute.
     */
    private function configuredAttribute(string $key, string $fallback): string
    {
        $attribute = config("activity.causer_suggestions.{$key}", $fallback);

        return is_string($attribute) && trim($attribute) !== ''
            ? trim($attribute)
            : $fallback;
    }

    /**
     * Normalize a supported actor identifier.
     */
    private function identifier(mixed $value): string|int|null
    {
        return is_string($value) || is_int($value) ? $value : null;
    }

    /**
     * Normalize an optional scalar display value.
     */
    private function string(mixed $value): string
    {
        return is_scalar($value) || $value instanceof \Stringable
            ? trim((string) $value)
            : '';
    }
}
