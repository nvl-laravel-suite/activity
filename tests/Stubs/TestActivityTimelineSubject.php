<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Nvl\Activity\Traits\HasModelActivity;

/**
 * Activity timeline host fixture used by resolver and authorization tests.
 */
final class TestActivityTimelineSubject extends Model
{
    use HasModelActivity;

    protected $table = 'activity_timeline_subjects';

    protected $fillable = ['name'];

    public $timestamps = false;
}
