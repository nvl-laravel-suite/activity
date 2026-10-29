<?php

declare(strict_types=1);

namespace Nvl\Activity\Traits;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Ownership\ActivityOwnershipGuard;
use Nvl\Activity\Services\ActivityAccess;
use Nvl\Activity\Services\ActivityCaptureContext;
use Nvl\Activity\Services\ActivityModelRegistry;
use Nvl\Activity\Services\ActivityPolicyRegistry;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Support\ActivityMetadata;
use Nvl\Activity\Support\ModelActivityMappingResolver;
use Nvl\Support\Enums\SystemActor;

/**
 * Optional explicit-field CRUD capture through native complete operations.
 *
 * @mixin Model
 *
 * @api
 *
 * @nvl-consumer-relation activitiesAsSubject
 */
trait HasModelActivity
{
    /** @var array<string,array{attributes:array<string,mixed>,old:array<string,mixed>}> */
    private array $nativeActivityChanges = [];

    protected static function bootHasModelActivity(): void
    {
        static::deleting(static function (Model $subject): void {
            app(ActivityOwnershipGuard::class)->captureDeletingSubject($subject);
        });
        foreach (['created' => 'creating', 'updated' => 'updating', 'deleted' => 'deleting', 'restored' => 'restoring'] as $event => $before) {
            static::registerModelEvent($before, static function (self $model) use ($event): void {
                unset($model->nativeActivityChanges[$event]);
                $mapping = ModelActivityMappingResolver::forModel($model);
                $options = $mapping?->captureOptions();
                if ($options === null || ! in_array($event, $options->events, true)) {
                    return;
                }
                if ($event === 'updated' && self::isActivityRestoration($model)) {
                    return;
                }
                $values = $model->nativeActivityValues($options->fields);
                $original = (clone $model)->setRawAttributes($model->getRawOriginal());
                $old = $original->nativeActivityValues($options->fields);
                if ($event === 'updated' && $options->onlyDirty) {
                    $values = array_filter($values, static fn (mixed $value, string $field): bool => ! array_key_exists($field, $old) || $old[$field] !== $value, ARRAY_FILTER_USE_BOTH);
                    $old = array_intersect_key($old, $values);
                }
                if ($event === 'created') {
                    $old = [];
                } elseif ($event === 'deleted') {
                    $old = $values;
                    $values = [];
                }
                if ($values === [] && $old === []) {
                    return;
                }
                $actor = app(ActivityCaptureContext::class)->actor();
                app(ActivityAccess::class)->authorize($actor, 'record', $model);
                app(ActivityPolicyRegistry::class)->get($event)->prepare($model->getMorphClass(), $actor instanceof Model ? $actor->getMorphClass() : null, $actor instanceof SystemActor, $values, $old, []);
                $model->nativeActivityChanges[$event] = ['attributes' => $values, 'old' => $old];
            });
            static::registerModelEvent($event, static function (self $model) use ($event): void {
                $changes = $model->nativeActivityChanges[$event] ?? null;
                unset($model->nativeActivityChanges[$event]);
                if ($changes === null) {
                    return;
                }
                $mapping = ModelActivityMappingResolver::forModel($model);
                app(ActivityRecorder::class)->record([
                    'subject' => $event === 'created' ? (clone $model)->syncOriginal() : $model, 'event' => $event, 'log_name' => $mapping?->logName() ?? 'default',
                    'attributes' => $changes['attributes'], 'old' => $changes['old'], 'resolve_changes' => false,
                ], app(ActivityCaptureContext::class)->actor());
            });
        }
    }

    /** @param list<string> $fields
     * @return array<string,mixed>
     */
    private function nativeActivityValues(array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            if (! array_key_exists(explode('.', $field, 2)[0], $this->getAttributes())) {
                continue;
            }
            $values[$field] = self::nativeActivityValue(data_get($this, $field));
        }

        return ActivityMetadata::record($values, 'captured changes');
    }

    private static function isActivityRestoration(Model $model): bool
    {
        if (! method_exists($model, 'getDeletedAtColumn')) {
            return false;
        }
        $column = $model->getDeletedAtColumn();

        return is_string($column) && $model->isDirty($column) && $model->getOriginal($column) !== null && $model->getAttribute($column) === null;
    }

    private static function nativeActivityValue(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        return is_array($value) ? array_map(self::nativeActivityValue(...), $value) : $value;
    }

    /** @return MorphMany<ActivityLog,$this> @internal */
    public function activitiesAsSubject(): MorphMany
    {
        return $this->morphMany(ActivityModelRegistry::modelClass(), 'subject');
    }
}
