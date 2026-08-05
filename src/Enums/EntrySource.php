<?php

declare(strict_types=1);

namespace Nvl\Activity\Enums;

/**
 * Identifies the origin of a normalized timeline entry.
 */
enum EntrySource: string
{
    case ActivityLog = 'activity_log';
    case Mail = 'mail';
    case Comment = 'comment';

    /**
     * Localized label for display.
     *
     * @return string Translated label
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::ActivityLog => (string) trans('activity::activity/general.sources.activity_log'),
            self::Mail => (string) trans('activity::activity/general.sources.mail'),
            self::Comment => (string) trans('activity::activity/general.sources.comment'),
        };
    }
}
