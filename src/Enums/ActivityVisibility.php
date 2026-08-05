<?php

declare(strict_types=1);

namespace Nvl\Activity\Enums;

/**
 * Defines where an activity entry is intended to appear.
 */
enum ActivityVisibility: string
{
    case AuditOnly = 'audit_only';
    case Hidden = 'hidden';
    case Timeline = 'timeline';

    /**
     * Return the localized visibility label.
     */
    public function getLabel(): string
    {
        return (string) trans("activity::activity/general.enums.visibility.{$this->value}");
    }
}
