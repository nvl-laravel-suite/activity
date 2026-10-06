<?php

declare(strict_types=1);

namespace Nvl\Activity\Traits;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Nvl\Activity\Data\Display\ActivityItem;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\NvlActivityLogger;
use Nvl\Activity\Support\ModelActivityMappingResolver;
use Nvl\Activity\Support\ModelActivityTimelineResolver;
use Nvl\Activity\Tenancy\ActivityOwnershipGuard;
use Spatie\Activitylog\Enums\ActivityEvent;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Adds the shared activity-log abstraction used across host and dependent models.
 *
 * Timeline host models use this trait together with `buildActivityTimeline()` to
 * expose operator-facing merged history. Dependent sub-models may also use the
 * trait for narrow self-auditing so every module relies on the same shared
 * logging seam instead of mixing raw `LogsActivity` usage with host-specific
 * abstractions.
 *
 * @method \Illuminate\Database\Eloquent\Relations\MorphMany<\Nvl\Activity\Models\ActivityLog, $this> activitiesAsSubject()
 */
trait HasModelActivity
{
    use LogsActivity;

    /** Register native change capture with a package-owned writer. */
    protected static function bootLogsActivity(): void
    {
        static::eventsToBeRecorded()->each(static function (mixed $eventName): void {
            if (! is_string($eventName) || $eventName === '') {
                throw new \InvalidArgumentException('NVL activity event names must be nonempty strings.');
            }
            if ($eventName === ActivityEvent::Updated->value) {
                static::updating(static function (self $model): void {
                    $oldValues = (new static)->setRawAttributes($model->getRawOriginal());
                    $model->oldAttributes = static::extractChanges($oldValues);
                });
            }

            static::$eventName(static function (self $model) use ($eventName): void {
                $model->activitylogOptions = $model->getActivitylogOptions();
                if (! $model->shouldLogEvent($eventName)) {
                    return;
                }
                $description = $model->getDescriptionForEvent($eventName);
                if ($description === '') {
                    return;
                }
                $changes = self::nvlActivityChanges($model->buildChanges($eventName));
                if ($model->shouldSkipEmptyLog($changes)) {
                    return;
                }
                app(NvlActivityLogger::class)->useLog($model->getLogNameToUse())
                    ->event($eventName)->performedOn($model)->withChanges($changes)->log($description);
                $model->activitylogOptions = null;
            });
        });
    }

    /**
     * Validate Spatie's untyped change payload before passing it to the native writer.
     *
     * @param  array<array-key, mixed>  $changes
     * @return array<string, mixed>
     */
    private static function nvlActivityChanges(array $changes): array
    {
        $validated = [];
        foreach ($changes as $key => $value) {
            if (! is_string($key)) {
                throw new \InvalidArgumentException('NVL activity changes must use named attributes.');
            }
            $validated[$key] = $value;
        }

        return $validated;
    }

    /** @return MorphMany<ActivityLog, $this> */
    public function activitiesAsSubject(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'subject');
    }

    /** Capture canonical ownership while deleted subjects still exist in storage. */
    protected static function bootHasModelActivity(): void
    {
        static::deleting(static function (Model $subject): void {
            Container::getInstance()->make(ActivityOwnershipGuard::class)->captureDeletingSubject($subject);
        });
    }

    /**
     * Resolve mapping-owned model capture configuration.
     *
     * Unmapped models remain silent so importing the shared trait can never
     * create broad or empty audit rows by accident.
     */
    public function getActivitylogOptions(): LogOptions
    {
        $mapping = ModelActivityMappingResolver::forModel($this);

        if ($mapping === null) {
            return LogOptions::defaults()
                ->logOnly([])
                ->dontLogEmptyChanges();
        }

        $options = $mapping->logOptions();
        $logName = trim($mapping->logName());

        if ($logName !== '') {
            $options->useLogName($logName);
        }

        return $options->dontLogEmptyChanges();
    }

    /**
     * Resolve the transformed base activity timeline for the current subject.
     *
     * Host models use this as the base activity source before layering richer
     * sources such as comments or mail notifications. Dependent models may call
     * it for self-auditing reads, but they do not become merged timeline hosts
     * merely by using this trait.
     *
     * @param  int|null  $limit  Optional maximum number of newest activity rows to return
     * @return array<int, ActivityItem>
     */
    public function mergedActivities(?int $limit = null): array
    {
        return ModelActivityTimelineResolver::forSubject($this, $limit);
    }
}
