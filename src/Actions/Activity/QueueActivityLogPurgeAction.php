<?php

declare(strict_types=1);

namespace Nvl\Activity\Actions\Activity;

use Illuminate\Support\Facades\DB;
use Nvl\Activity\Contracts\QueueActivityLogPurgeContract;
use Nvl\Activity\Events\ActivityLogPurgeQueued;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Support\ActivityPurgeCriteria;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope;

/**
 * Queue purge work for activity logs.
 *
 * @api
 */
final readonly class QueueActivityLogPurgeAction implements QueueActivityLogPurgeContract
{
    /** Create the tenant-aware queue boundary. */
    public function __construct(private TenantContext $tenantContext, private ?DomainEventDispatcher $domainEvents = null) {}

    /**
     * Queue the activity log purge job.
     *
     * @param  int  $days  Delete logs older than this many days
     * @param  bool  $systemOnly  Only purge system-generated logs
     * @param  bool  $includeImportant  Explicitly include protected important evidence
     */
    public function execute(
        int $days,
        bool $systemOnly = false,
        bool $includeImportant = false,
    ): void {
        $criteria = ActivityPurgeCriteria::fromDays($days, $systemOnly, $includeImportant);

        $envelope = TenantJobEnvelope::capture($this->tenantContext);
        PurgeActivityLogsJob::dispatch($days, $systemOnly, $criteria, $envelope);

        ($this->domainEvents ?? app(DomainEventDispatcher::class))->dispatch(new ActivityLogPurgeQueued($days, $systemOnly, $includeImportant), DB::connection(PackageStorage::connection('activity')));
    }
}
