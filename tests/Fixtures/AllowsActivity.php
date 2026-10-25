<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Activity\Contracts\ActivityAuthorization;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Support\Enums\SystemActor;

/** Deliberate host policy for successful native package scenarios. */
final class AllowsActivity implements ActivityAuthorization
{
    public function authorize(Authenticatable|SystemActor $actor, string $ability, Model|ActivitySubjectReference|null $subject = null): void {}
}
