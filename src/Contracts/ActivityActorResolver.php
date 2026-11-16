<?php

declare(strict_types=1);

namespace Nvl\Activity\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves legacy positional actor references to a host-authenticated principal.
 *
 * @api
 */
interface ActivityActorResolver
{
    public function resolve(Model|string|int|null $actor): ?Authenticatable;
}
