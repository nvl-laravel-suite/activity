<?php

declare(strict_types=1);

namespace Nvl\Activity\Enums;

/**
 * Defines package-owned event names that carry the same meaning across domains.
 *
 * Sent and resent describe business actions performed on the activity subject.
 * Mail transport lifecycle remains owned by the native mail notification source.
 */
enum ActivityEvent: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';
    case Assigned = 'assigned';
    case DetailsUpdated = 'details_updated';
    case StatusChanged = 'status_changed';
    case StatusTransition = 'status_transition';
    case StatusOverride = 'status_override';
    case Viewed = 'viewed';
    case Activated = 'activated';
    case Deactivated = 'deactivated';
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Archived = 'archived';
    case Unarchived = 'unarchived';
    case Triggered = 'triggered';
    case Retried = 'retried';
    case Sent = 'sent';
    case Resent = 'resent';

    /**
     * Return the localized event label.
     */
    public function getLabel(): string
    {
        return (string) trans("activity::activity/general.events.{$this->value}");
    }
}
