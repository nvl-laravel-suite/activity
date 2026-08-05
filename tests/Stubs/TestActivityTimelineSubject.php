<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Nvl\Activity\Contracts\MergesActivity;
use Nvl\Activity\Data\Display\ActivityItem;
use Nvl\Activity\Traits\HasModelActivity;
use Nvl\Activity\Traits\MergesActivityTimeline;

/**
 * Activity timeline host fixture used by resolver and authorization tests.
 */
final class TestActivityTimelineSubject extends Model implements MergesActivity
{
    use HasModelActivity;
    use MergesActivityTimeline;

    protected $table = 'activity_timeline_subjects';

    protected $fillable = ['name'];

    public $timestamps = false;

    /**
     * Return no additional activity sources for the fixture host.
     *
     * @return array<int, iterable<int|string, ActivityItem>>
     */
    protected function mergedActivitySources(?int $limit = null): array
    {
        return [];
    }
}
