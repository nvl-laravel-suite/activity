<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

/** Ordinary Laravel actor admitted by the fixture host policy. */
final class OwnershipActor extends User
{
    protected $table = 'activity_test_actors';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;
}
