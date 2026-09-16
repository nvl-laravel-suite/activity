<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** Represents the explicitly configured shared identity projection used by Activity. */
final class GlobalActivityCauser extends Model
{
    protected $table = 'global_activity_causers';

    protected $guarded = [];
}
