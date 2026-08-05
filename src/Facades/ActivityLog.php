<?php

declare(strict_types=1);

namespace Nvl\Activity\Facades;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Nvl\Activity\Services\ActivityRecorder;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;

/**
 * @method static ActivityContract|null record(?Model $subject, string|BackedEnum $event, string $description = '', array<string, mixed> $context = [], array<string, mixed>|null $attributes = null, array<string, mixed>|null $old = null, Model|string|int|null $actor = null, ?string $logName = null, string|BackedEnum|null $source = null, string|BackedEnum|null $visibility = null, string|BackedEnum|null $importance = null, bool $resolveChanges = true, ?string $batchUuid = null)
 *
 * @see ActivityRecorder
 */
final class ActivityLog extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string Facade accessor key
     */
    protected static function getFacadeAccessor(): string
    {
        return ActivityRecorder::class;
    }
}
