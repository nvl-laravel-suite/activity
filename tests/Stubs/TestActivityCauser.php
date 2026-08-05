<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

/**
 * Configurable non-UUID causer fixture for standalone Activity tests.
 */
final class TestActivityCauser extends Model
{
    protected $table = 'activity_test_causers';

    protected $primaryKey = 'causer_key';

    protected $guarded = [];

    public $timestamps = false;
}
