<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;
use Nvl\Activity\Support\ActivityEventPolicy;
use Nvl\Support\Enums\SystemActor;

/**
 * Closed event catalog; duplicate or late registration cannot change admitted facts.
 *
 * @api
 */
final class ActivityPolicyRegistry
{
    /** @var array<string,ActivityEventPolicy> */
    private array $policies = [];

    private bool $frozen = false;

    public function register(ActivityEventPolicy $policy): void
    {
        if ($this->frozen || isset($this->policies[$policy->event])) {
            throw new LogicException('Activity policies must be registered once before the catalog is used.');
        }
        $this->policies[$policy->event] = $policy;
    }

    public function get(string $event): ActivityEventPolicy
    {
        $this->frozen = true;

        return $this->policies[$event] ?? throw new InvalidArgumentException('Activity event ['.$event.'] has no registered policy.');
    }

    /** @return array<string,ActivityEventPolicy> */
    public function all(): array
    {
        $this->frozen = true;

        return $this->policies;
    }

    /** Apply restrictive audiences in SQL before pagination and hydration.
     * @internal
     *
     * @template TModel of \Nvl\Activity\Models\ActivityLog
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function visible(Builder $query, Authenticatable|SystemActor $actor, ActivityAccess $access): Builder
    {
        $restricted = array_filter($this->all(), static fn (ActivityEventPolicy $policy): bool => $policy->audience !== 'authorized');
        if ($restricted === []) {
            return $query;
        }
        try {
            $access->authorize($actor, 'read_all');

            return $query;
        } catch (AuthorizationException) {
            // Ordinary readers receive only policy-admitted references below.
        }

        return $query->where(function (Builder $query) use ($restricted, $actor): void {
            $query->whereNotIn('event', array_keys($restricted))->orWhereNull('event');
            if (! $actor instanceof Model) {
                return;
            }
            foreach ($restricted as $policy) {
                if ($policy->audience === 'administrative') {
                    continue;
                }
                $query->orWhere(function (Builder $event) use ($policy, $actor): void {
                    $event->where('event', $policy->event)->where(function (Builder $identities) use ($policy, $actor): void {
                        $identities->whereRaw('1 = 0');
                        foreach (['subject', 'causer'] as $side) {
                            if (($side === 'subject' && $policy->audience === 'actor') || ($side === 'causer' && $policy->audience === 'subject')) {
                                continue;
                            }
                            $identities->orWhere(fn (Builder $reference) => $reference->where($side.'_type', $actor->getMorphClass())->where($side.'_id', $actor->getKey()));
                        }
                    });
                });
            }
        });
    }

    /** Apply event policy ages in SQL before chunk selection; unknown and forever-retained events are preserved.
     * @internal
     *
     * @template TModel of \Nvl\Activity\Models\ActivityLog
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function retainable(Builder $query): Builder
    {
        $policies = $this->all();

        return $query->where(function (Builder $query) use ($policies): void {
            $query->whereRaw('1 = 0');
            foreach ($policies as $policy) {
                if ($policy->retentionDays !== null) {
                    $query->orWhere(fn (Builder $event) => $event->where('event', $policy->event)->where('created_at', '<', now()->subDays($policy->retentionDays)));
                }
            }
        });
    }
}
