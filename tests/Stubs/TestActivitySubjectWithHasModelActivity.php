<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Nvl\Activity\Traits\HasModelActivity;
use Spatie\Activitylog\Models\Activity;

/**
 * Capturable model fixture for mapping tests.
 */
final class TestActivitySubjectWithHasModelActivity extends Model
{
    use HasModelActivity;

    protected $table = 'activity_mapping_subjects';

    protected $fillable = ['name'];

    public $timestamps = false;

    public function customActivityKeyLabel(string $key, Activity $activity): string
    {
        return 'Legacy label';
    }

    /**
     * @return array<string, string>
     */
    public function registerValueMappings(string $key): array
    {
        return ['draft' => 'Legacy draft'];
    }
}
