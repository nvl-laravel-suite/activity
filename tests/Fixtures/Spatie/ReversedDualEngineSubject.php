<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Fixtures\Spatie;

use Illuminate\Database\Eloquent\Model;
use Nvl\Activity\Traits\HasModelActivity as NativeActivity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/**
 * Invalid model that uses both engines, Spatie trait first.
 *
 * The native trait is aliased so the formatter's trait ordering keeps Spatie first.
 */
final class ReversedDualEngineSubject extends Model
{
    use LogsActivity, NativeActivity {
        NativeActivity::activitiesAsSubject insteadof LogsActivity;
    }

    protected $table = 'native_bridge_subjects';

    protected $guarded = [];

    public $timestamps = false;
}
