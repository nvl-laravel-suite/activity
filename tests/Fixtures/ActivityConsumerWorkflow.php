<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Fixtures;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Queue;
use Nvl\Activity\Contracts\ActivityReadContract;
use Nvl\Activity\Contracts\ActivityRecorderContract;
use Nvl\Activity\Contracts\ListActivitiesContract;
use Nvl\Activity\Contracts\ListActivityCauserSuggestionsContract;
use Nvl\Activity\Contracts\QueueActivityLogPurgeContract;
use Nvl\Activity\Data\ActivityIndexFilter;
use Nvl\Activity\Data\Display\ActivityCauserSuggestion;
use Nvl\Activity\Data\Display\ActivityItem;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Support\ActivitySubjectReference;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\LaravelData\DataCollection;

/** A host-owned workflow resolved through ordinary constructor injection. */
final readonly class ActivityConsumerWorkflow
{
    /** Receive only supported package use-case boundaries. */
    public function __construct(
        private ListActivitiesContract $listing,
        private ListActivityCauserSuggestionsContract $causers,
        private QueueActivityLogPurgeContract $purge,
        private ActivityReadContract $reader,
        private ActivityRecorderContract $recorder,
    ) {}

    /** @return LengthAwarePaginator<int, ActivityItem> */
    public function index(ActivityIndexFilter $filters): LengthAwarePaginator
    {
        return $this->listing->execute($filters);
    }

    /** @return DataCollection<int, ActivityCauserSuggestion> */
    public function suggestions(string $search, int $limit): DataCollection
    {
        return $this->causers->execute($search, $limit);
    }

    /** @return EloquentCollection<int, ActivityLog> */
    public function history(ActivitySubjectReference $subject): EloquentCollection
    {
        return $this->reader->forSubjectKey($subject->type, $subject->id, 5);
    }

    /** Record a review through the value-only writer boundary. */
    public function record(ActivitySubjectReference $subject): ?ActivityContract
    {
        return $this->recorder->recordForSubjectReference($subject, 'reviewed');
    }

    /** Continue host orchestration after the package's void queue boundary. */
    public function purgeSystemHistory(): string
    {
        $this->purge->execute(365, true, false);

        return 'queued';
    }

    /** Expose injected identity solely for the host replacement regression. */
    public function dependency(string $contract): object
    {
        return match ($contract) {
            ListActivitiesContract::class => $this->listing,
            ListActivityCauserSuggestionsContract::class => $this->causers,
            QueueActivityLogPurgeContract::class => $this->purge,
            ActivityReadContract::class => $this->reader,
            ActivityRecorderContract::class => $this->recorder,
            default => throw new InvalidArgumentException('Unknown Activity workflow contract.'),
        };
    }
}
