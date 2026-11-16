<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Stubs;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Nvl\Activity\Traits\HasModelActivity;

/**
 * UUID-keyed host model exposing the Activity subject relation for existence queries.
 */
final class TestUuidActivityModelSubject extends Model
{
    use HasModelActivity;
    use HasUuids;

    protected $table = 'activity_uuid_subjects';

    protected $fillable = ['name'];

    public $timestamps = false;
}
