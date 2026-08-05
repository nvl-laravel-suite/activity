<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Soft-deletable causer fixture for historical suggestion tests.
 */
final class TestSoftDeletedActivityCauser extends Model
{
    use SoftDeletes;

    protected $table = 'activity_soft_deleted_causers';

    protected $fillable = [
        'display_name',
        'contact',
    ];

    public $timestamps = false;
}
