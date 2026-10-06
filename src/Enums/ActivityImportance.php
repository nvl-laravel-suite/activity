<?php

declare(strict_types=1);

namespace Nvl\Activity\Enums;

/**
 * Defines the semantic importance level of an activity entry.
 *
 * @api
 */
enum ActivityImportance: string
{
    case Low = 'low';
    case Normal = 'normal';
    case Important = 'important';

    /**
     * Return the localized importance label.
     */
    public function getLabel(): string
    {
        return (string) trans("nvl-activity::activity/general.enums.importance.{$this->value}");
    }
}
