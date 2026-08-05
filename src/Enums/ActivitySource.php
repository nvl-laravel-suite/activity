<?php

declare(strict_types=1);

namespace Nvl\Activity\Enums;

/**
 * Defines the origin of a recorded activity entry.
 */
enum ActivitySource: string
{
    case System = 'system';
    case User = 'user';

    /**
     * Return the localized activity-origin label.
     */
    public function getLabel(): string
    {
        return (string) trans("activity::activity/general.enums.source.{$this->value}");
    }
}
