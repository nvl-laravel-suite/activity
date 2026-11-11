<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Stubs;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Nvl\Activity\Traits\HasModelActivity;

/**
 * Soft-deletable authenticatable fixture whose own lifecycle is captured.
 */
final class TestSoftDeletedActivityAccount extends Authenticatable
{
    use HasModelActivity;
    use SoftDeletes;

    protected $table = 'activity_mapping_subjects';

    protected $guarded = [];

    public $timestamps = false;
}
