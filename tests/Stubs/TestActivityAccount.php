<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Stubs;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Nvl\Activity\Traits\HasModelActivity;

/**
 * Authenticatable fixture whose own lifecycle is captured.
 */
final class TestActivityAccount extends Authenticatable
{
    use HasModelActivity;

    protected $table = 'activity_mapping_subjects';

    protected $guarded = [];

    public $timestamps = false;
}
