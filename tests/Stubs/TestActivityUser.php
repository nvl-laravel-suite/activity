<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Stubs;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Standalone authenticated-user fixture for package authorization tests.
 */
final class TestActivityUser extends Authenticatable
{
    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    public $timestamps = false;
}
