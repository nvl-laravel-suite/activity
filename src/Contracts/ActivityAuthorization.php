<?php

declare(strict_types=1);

namespace Nvl\Activity\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Support\Enums\SystemActor;

/**
 * Consumer-owned authority for direct Activity operations.
 *
 * @api
 */
interface ActivityAuthorization
{
    /** Authorize record, read, timeline, causers or purge; throw on denial. */
    public function authorize(Authenticatable|SystemActor $actor, string $ability, Model|ActivitySubjectReference|null $subject = null): void;
}
