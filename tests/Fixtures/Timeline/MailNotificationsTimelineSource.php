<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Fixtures\Timeline;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Activity\Contracts\ActivityTimelineSource;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\MailNotifications\Contracts\MailNotificationReadAuthorization;
use Nvl\MailNotifications\Enums\MailNotificationReadAbility;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Services\MailModelRegistry;

/**
 * Copyable recipe: a delivery summary of the tracked mail sent about the timeline subject.
 *
 * @implements ActivityTimelineSource<MailNotification>
 */
final readonly class MailNotificationsTimelineSource implements ActivityTimelineSource
{
    public function __construct(private Authenticatable $actor) {}

    /** @return Builder<MailNotification> */
    public function query(Model|ActivitySubjectReference $subject): Builder
    {
        app(MailNotificationReadAuthorization::class)->authorize(MailNotificationReadAbility::List, $this->actor);
        $reference = self::reference($subject);

        // Unlike MailHistory, this does not apply OwnershipBoundary itself: ActivityReader::timeline()
        // applies it to every source query when Ownership is enabled, and without Ownership it only
        // checks storage. Calling query() outside timeline()? Wrap it in
        // app(OwnershipBoundary::class)->query($query, 'mail.notifications') first.

        return app(MailModelRegistry::class)->notificationClass()::query()
            ->where('notifiable_type', $reference->type)
            ->where('notifiable_id', (string) $reference->id)
            ->select(['id', 'created_at', 'status', 'message_category']);
    }

    /** @return list<string> Mail delivery never replaces a business event. */
    public function supersedes(): array
    {
        return [];
    }

    /** Read the subject's morph type and scalar key. */
    private static function reference(Model|ActivitySubjectReference $subject): ActivitySubjectReference
    {
        if ($subject instanceof ActivitySubjectReference) {
            return $subject;
        }
        $key = $subject->getKey();
        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidArgumentException('Timeline subjects require a scalar key.');
        }

        return new ActivitySubjectReference($subject->getMorphClass(), $key);
    }
}
