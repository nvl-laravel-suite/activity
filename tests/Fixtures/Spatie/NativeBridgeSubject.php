<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Fixtures\Spatie;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Nvl\Activity\Traits\HasModelActivity;

/**
 * Native-only model with the Spatie fixture's schema, in its own table.
 */
final class NativeBridgeSubject extends Model
{
    use HasModelActivity;
    use SoftDeletes;

    protected $table = 'native_bridge_subjects';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['priority' => 'integer'];
    }
}
