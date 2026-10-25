<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Activity\Contracts\ActivityAuthorization;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Support\Enums\SystemActor;

/** Validates an explicit principal before consulting the host policy. @internal */
final readonly class ActivityAccess
{
    public function __construct(private ?ActivityAuthorization $authorization = null) {}

    /** Missing policy and transient or substituted identities always fail closed. */
    public function authorize(Authenticatable|SystemActor $actor, string $ability, Model|ActivitySubjectReference|null $subject = null): void
    {
        if (! $actor instanceof SystemActor) {
            if (! $actor instanceof Model || ! $actor->exists || $actor->getKey() === null
                || $actor->getRawOriginal($actor->getKeyName()) !== $actor->getKey()) {
                throw new AuthorizationException('Activity operations require a persisted actor.');
            }
            $canonical = new ($actor::class);
            if ($canonical->getTable() !== $actor->getTable()
                || $canonical->getKeyName() !== $actor->getKeyName()
                || $canonical->getConnection()->getName() !== $actor->getConnection()->getName()) {
                throw new AuthorizationException('Activity actor storage cannot be substituted.');
            }
            $persisted = $canonical->newQuery()->whereKey($actor->getKey())->first();
            if (! $persisted instanceof Authenticatable) {
                throw new AuthorizationException('Activity operations require a persisted actor.');
            }
            $actor = $persisted;
        }
        if ($this->authorization === null) {
            throw new AuthorizationException('Bind ActivityAuthorization to authorize Activity operations.');
        }
        $this->authorization->authorize($actor, $ability, $subject);
    }
}
