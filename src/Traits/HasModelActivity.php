<?php

declare(strict_types=1);

namespace Nvl\Activity\Traits;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Ownership\ActivityOwnershipGuard;
use Nvl\Activity\Services\ActivityAccess;
use Nvl\Activity\Services\ActivityCaptureContext;
use Nvl\Activity\Services\NvlActivityLogger;
use Nvl\Activity\Support\ActivityMetadata;
use Nvl\Activity\Support\ModelActivityMappingResolver;
use Nvl\Support\Enums\SystemActor;
use Spatie\Activitylog\Enums\ActivityEvent;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\ChangeDetector;
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
 *
 * @api
 *
 * @nvl-consumer-relation activitiesAsSubject
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
            $beforeEvent = match ($eventName) {
                'created' => 'creating', 'updated' => 'updating', 'deleted' => 'deleting', 'restored' => 'restoring',
                default => null,
            };
            if ($beforeEvent !== null) {
                static::registerModelEvent($beforeEvent, static function (self $model) use ($eventName): void {
                    $model->activitylogOptions = $model->getActivitylogOptions();
                    if (! $model->shouldLogEvent($eventName) || $model->getDescriptionForEvent($eventName) === '') {
                        return;
                    }
                    $changes = $eventName === ActivityEvent::Updated->value
                        ? $model->prospectiveActivityChanges()
                        : $model->buildChanges($eventName);
                    if ($model->shouldSkipEmptyLog($changes)) {
                        return;
                    }
                    $actor = app(ActivityCaptureContext::class)->actor();
                    app(ActivityAccess::class)->authorize($actor, 'record', $model);
                });
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
                $loggingSubject = $eventName === ActivityEvent::Created->value ? (clone $model)->syncOriginal() : $model;
                $changes = self::nvlActivityChanges($loggingSubject->buildChanges($eventName));
                if ($model->shouldSkipEmptyLog($changes)) {
                    return;
                }
                $actor = app(ActivityCaptureContext::class)->actor();
                app(ActivityAccess::class)->authorize($actor, 'record', $loggingSubject);
                $logger = app(NvlActivityLogger::class)->useLog($model->getLogNameToUse())
                    ->event($eventName)->performedOn($loggingSubject)
                    ->withChanges(ActivityMetadata::redact($changes))
                    ->withProperties(['source' => $actor instanceof SystemActor ? 'system' : 'user']);
                if ($actor instanceof Model) {
                    $logger->causedBy($actor);
                } else {
                    $logger->causedByAnonymous();
                }
                $logger->log($description);
                $model->activitylogOptions = null;
            });
        });
    }

    /**
     * Compare pending attributes with the original snapshot without reloading or consuming post-save state.
     *
     * @return array<string, mixed>
     */
    private function prospectiveActivityChanges(): array
    {
        if ($this->attributesToBeLogged() === []) {
            return [];
        }
        $options = $this->activitylogOptions ?? $this->getActivitylogOptions();
        $pending = clone $this;
        $original = (clone $this)->setRawAttributes($this->getRawOriginal())->unsetRelations();
        $attributes = static::extractChanges($pending);
        $old = static::extractChanges($original);

        return $options->logOnlyDirty
            ? self::nvlActivityChanges(ChangeDetector::filterDirty($attributes, $old))
            : ['attributes' => $attributes, 'old' => $old];
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

    /**
     * @return MorphMany<ActivityLog, $this>
     *
     * @internal
     */
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
}
