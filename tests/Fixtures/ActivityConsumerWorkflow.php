<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Pagination\LengthAwarePaginator;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityReader;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Support\Enums\SystemActor;

/** Consumer-owned orchestration with explicit native package dependencies. */
final readonly class ActivityConsumerWorkflow
{
    public function __construct(private ActivityReader $reader, private ActivityRecorder $recorder) {}

    /** @return LengthAwarePaginator<int, ActivityLog> */
    public function history(ActivitySubjectReference $subject, Authenticatable|SystemActor $actor): LengthAwarePaginator
    {
        return $this->reader->paginate(['subjects' => [$subject], 'per_page' => 5], $actor);
    }

    public function record(ActivitySubjectReference $subject, Authenticatable|SystemActor $actor): ActivityLog
    {
        return $this->recorder->record(['subject' => $subject, 'event' => 'reviewed'], $actor);
    }
}
