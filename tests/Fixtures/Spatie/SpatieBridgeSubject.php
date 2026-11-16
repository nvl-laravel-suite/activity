<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Fixtures\Spatie;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Spatie-only model whose options the native bridge must reproduce.
 */
final class SpatieBridgeSubject extends Model
{
    use LogsActivity;
    use SoftDeletes;

    public static bool $logOnlyDirty = true;

    public static bool $dontLogEmptyChanges = true;

    protected $table = 'spatie_bridge_subjects';

    protected $guarded = [];

    public $timestamps = false;

    public function getActivitylogOptions(): LogOptions
    {
        $options = LogOptions::defaults()
            ->logOnly(['name', 'priority', 'secret'])->logExcept(['secret'])
            ->useLogName('bridge');

        if (self::$logOnlyDirty) {
            $options->logOnlyDirty();
        }

        return self::$dontLogEmptyChanges ? $options->dontLogEmptyChanges() : $options;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['priority' => 'integer'];
    }
}
