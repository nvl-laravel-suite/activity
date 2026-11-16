<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Fixtures\Spatie;

use Illuminate\Database\Eloquent\Model;
use Nvl\Activity\Traits\HasModelActivity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/**
 * Invalid model that uses both engines, native trait first.
 */
final class DualEngineSubject extends Model
{
    use HasModelActivity, LogsActivity {
        HasModelActivity::activitiesAsSubject insteadof LogsActivity;
    }

    protected $table = 'native_bridge_subjects';

    protected $guarded = [];

    public $timestamps = false;
}
