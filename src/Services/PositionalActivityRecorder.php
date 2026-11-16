<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use BackedEnum;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Activity\Contracts\ActivityActorResolver;
use Nvl\Activity\Enums\ActivityImportance;
use Nvl\Activity\Enums\ActivitySource;
use Nvl\Activity\Enums\ActivityVisibility;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Support\Enums\SystemActor;

/**
 * Adapts positional calls to the native ActivityRecorder and its host policy.
 *
 * @api
 */
final readonly class PositionalActivityRecorder
{
    public function __construct(private ActivityRecorder $recorder, private ?ActivityActorResolver $actors = null) {}

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>|null  $attributes
     * @param  array<string,mixed>|null  $old
     */
    public function record(
        ?Model $subject,
        string|BackedEnum $event,
        string $description,
        array $context = [],
        ?array $attributes = null,
        ?array $old = null,
        Authenticatable|SystemActor|Model|string|int|null $actor = null,
        ?string $logName = null,
        string|BackedEnum|null $source = null,
        string|BackedEnum|null $visibility = ActivityVisibility::Timeline,
        string|BackedEnum|null $importance = ActivityImportance::Normal,
        bool $resolveChanges = true,
    ): ActivityLog {
        $principal = $actor instanceof Authenticatable || $actor instanceof SystemActor ? $actor : $this->actors?->resolve($actor);
        if ($principal === null) {
            throw new AuthorizationException('An explicit or host-resolved Activity actor is required.');
        }
        $expectedSource = $principal instanceof SystemActor ? ActivitySource::System : ActivitySource::User;
        if ($source !== null && ($source instanceof BackedEnum ? $source->value : $source) !== $expectedSource->value) {
            throw new InvalidArgumentException('Activity source must match the admitted actor.');
        }

        return $this->recorder->record([
            'subject' => $subject, 'event' => $event, 'description' => $description,
            'context' => $context, 'log_name' => $logName ?? '',
            'visibility' => $visibility instanceof BackedEnum ? $visibility->value : $visibility,
            'importance' => $importance instanceof BackedEnum ? $importance->value : $importance,
            'resolve_changes' => $resolveChanges,
            ...($attributes === null ? [] : ['attributes' => $attributes]),
            ...($old === null ? [] : ['old' => $old]),
        ], $principal);
    }
}
