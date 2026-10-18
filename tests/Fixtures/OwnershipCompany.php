<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** Native host owner without an NVL tenancy or authentication package. */
final class OwnershipCompany extends Model
{
    protected $table = 'activity_test_companies';

    protected $guarded = [];

    public $timestamps = false;
}
