<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Support\Enums\SystemActor;

/**
 * Explicit, nested attribution for synchronous model event capture.
 *
 * @api
 */
final class ActivityCaptureContext
{
    private Authenticatable|SystemActor|null $current = null;

    /**
     * Restore the prior principal after success or failure; capture authorizes each actual event.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public function run(Authenticatable|SystemActor $actor, callable $operation): mixed
    {
        $previous = $this->current;
        $this->current = $actor;
        try {
            return $operation();
        } finally {
            $this->current = $previous;
        }
    }

    /** Refuse an implicit system or HTTP actor when capture has no explicit host scope. */
    public function actor(): Authenticatable|SystemActor
    {
        return $this->current ?? throw new AuthorizationException('Model activity capture requires an explicit ActivityCaptureContext.');
    }
}
