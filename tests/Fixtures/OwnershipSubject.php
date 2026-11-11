<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Model;
use Nvl\Activity\Traits\HasModelActivity;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** Native owned, authenticatable subject whose current persisted identity controls Activity relation resolution. */
final class OwnershipSubject extends Model implements Ownable
{
    use HasModelActivity;
    use HasOwnership;

    protected $table = 'activity_test_subjects';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;

    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::column(OwnershipCompany::class, 'company_id');
    }
}
