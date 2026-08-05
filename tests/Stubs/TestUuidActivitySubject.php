<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Stubs;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * UUID-keyed subject fixture for historical morph relation tests.
 */
final class TestUuidActivitySubject extends Model
{
    use HasUuids;

    protected $table = 'activity_uuid_subjects';

    protected $fillable = ['name'];

    public $timestamps = false;
}
