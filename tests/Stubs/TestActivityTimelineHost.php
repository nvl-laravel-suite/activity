<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Stubs;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Activity\Contracts\ActivityTimelineSource;
use Nvl\Activity\Support\ActivitySubjectReference;

/**
 * Consumer timeline source delegates row storage to its own native model.
 *
 * @implements ActivityTimelineSource<TestTimelineSourceRecord>
 */
final class TestActivityTimelineHost implements ActivityTimelineSource
{
    /** @return Builder<TestTimelineSourceRecord> */
    public function query(Model|ActivitySubjectReference $subject): Builder
    {
        return TestTimelineSourceRecord::query()->where('subject_id', $subject instanceof Model ? $subject->getKey() : $subject->id);
    }

    /** @return list<string> */
    public function supersedes(): array
    {
        return ['comment_recorded'];
    }
}
