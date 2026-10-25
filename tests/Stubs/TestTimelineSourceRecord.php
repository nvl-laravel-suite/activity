<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

/** Consumer-owned native timeline record. */
/** @property string $subject_id */
final class TestTimelineSourceRecord extends Model
{
    protected $table = 'activity_external_timeline';

    protected $guarded = [];

    public $timestamps = false;
}
