<?php

declare(strict_types=1);

namespace Nvl\Activity\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Activity\Exceptions\ActivityException;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Support\ActivityPurgeCriteria;

/**
 * Artisan command to purge old activity logs, optionally system-only.
 */
final class PurgeActivityLogsCommand extends Command
{
    /**
     * @var string Console command signature
     */
    protected $signature = 'nvl:activity:purge
        {--days= : Delete logs older than this many days}
        {--before= : Delete logs created before this absolute date/time}
        {--after= : Only delete logs created on or after this absolute date/time}
        {--event=* : Restrict purge to one or more event names}
        {--log-name=* : Restrict purge to one or more log names}
        {--subject-type=* : Restrict purge to one or more subject class names}
        {--subject-id=* : Restrict purge to one or more subject identifiers}
        {--causer-type=* : Restrict purge to one or more causer class names}
        {--causer-id=* : Restrict purge to one or more causer identifiers}
        {--system-only : Only purge system-generated logs (no causer)}
        {--dry-run : Report count without deleting}';

    /**
     * @var string Console command description
     */
    protected $description = 'Purge activity log entries older than a given number of days';

    /**
     * Execute the console command.
     *
     * @return int Exit code
     */
    public function handle(): int
    {
        try {
            $criteria = ActivityPurgeCriteria::fromConsoleOptions(
                $this->options(),
                defaultDays: $this->configuredDefaultDays(),
            );
        } catch (ActivityException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->writeCriteriaSummary($criteria);

        if ((bool) $this->option('dry-run')) {
            $count = PurgeActivityLogsJob::countPurgeableForCriteria($criteria);
            $this->info((string) trans(
                'activity::activity/general.console.purge.dry_run',
                ['count' => $count],
            ));

            return self::SUCCESS;
        }

        PurgeActivityLogsJob::dispatch($criteria->days ?? 0, $criteria->systemOnly, $criteria);

        $this->info((string) trans('activity::activity/general.console.purge.dispatched'));

        return self::SUCCESS;
    }

    /**
     * Render a concise summary of the active purge criteria.
     */
    private function writeCriteriaSummary(ActivityPurgeCriteria $criteria): void
    {
        foreach ($criteria->summaryParts() as $part) {
            $this->line($part);
        }
    }

    /**
     * Resolve the configured default retention window.
     */
    private function configuredDefaultDays(): int
    {
        $days = config('activity.retention.default_days', 365);

        return is_int($days) && $days > 0 ? $days : 365;
    }
}
