<?php

declare(strict_types=1);

namespace Nvl\Activity\Contracts;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Per-resource interface for activity field/value mapping and display.
 *
 * Replaces ad-hoc trait hooks (registerPropertyMappings, customActivityKeyLabel,
 * registerValueMappings) with a typed, discoverable contract.
 */
interface ActivityMapping
{
    /**
     * FQCN of the model this mapping applies to.
     *
     * @return class-string
     */
    public function modelClass(): string;

    /**
     * Resolve the human entity type used in headlines.
     */
    public function entityLabel(): string;

    /**
     * Resolve the human identifier for one concrete subject instance.
     */
    public function subjectLabel(Model $subject): string;

    /**
     * Resolve the stable Spatie log channel name.
     */
    public function logName(): string;

    /**
     * Build the Spatie automatic-capture options for the mapped model.
     */
    public function logOptions(): LogOptions;

    /**
     * Resolve a persisted field key to its display label.
     */
    public function fieldLabel(string $key): string;

    /**
     * Resolve a persisted field value to its display representation.
     */
    public function fieldValue(string $key, mixed $value): string;

    /**
     * Event + properties -> display value for headline templates.
     *
     * @param  array<string, mixed>  $properties
     */
    public function eventDisplayValue(string $event, array $properties): ?string;

    /**
     * Custom event templates beyond the shared ones (optional, default empty).
     *
     * @return array<string, string>
     */
    public function eventTemplates(): array;
}
