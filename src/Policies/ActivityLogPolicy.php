<?php

declare(strict_types=1);

namespace Nvl\Activity\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Contracts\Auth\Authenticatable as User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Nvl\Activity\Models\ActivityLog;

/**
 * Authorization policy for activity log access and maintenance operations.
 */
final class ActivityLogPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view the activity log index.
     */
    public function viewAny(?User $user): bool
    {
        return $this->allows($user, 'view');
    }

    /**
     * Determine whether the user can view a single activity log entry.
     */
    public function view(?User $user, ActivityLog $activityLog): bool
    {
        return $this->allows($user, 'view', [$activityLog]);
    }

    /**
     * Determine whether the user can view a host-owned subject timeline.
     */
    public function viewTimeline(?User $user, Model $subject): bool
    {
        return $this->allows($user, 'timeline', [$subject]);
    }

    /**
     * Determine whether the user can delete activity log entries via purge flows.
     */
    public function delete(?User $user): bool
    {
        return $this->allows($user, 'purge');
    }

    /**
     * Determine whether the authenticated user passes the configured gate.
     *
     * @param  array<int, mixed>  $arguments
     */
    private function allows(?User $user, string $operation, array $arguments = []): bool
    {
        if ($user === null) {
            return false;
        }

        $ability = config("activity.authorization.abilities.{$operation}");

        if (! is_string($ability) || trim($ability) === '') {
            return false;
        }

        return Gate::forUser($user)->allows(trim($ability), $arguments);
    }
}
