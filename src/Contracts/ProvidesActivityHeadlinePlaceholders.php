<?php

declare(strict_types=1);

namespace Nvl\Activity\Contracts;

/**
 * Supplies named semantic placeholders for module-owned activity headlines.
 *
 * This extends the flat eventDisplayValue pattern for events that need more
 * than one meaningful token, such as old/new names or feed type + locale.
 */
interface ProvidesActivityHeadlinePlaceholders
{
    /**
     * Resolve named semantic placeholders for a module-owned event template.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, array{type: string, text: string, causerId?: string|int|null}>
     */
    public function eventHeadlinePlaceholders(string $event, array $properties): array;
}
