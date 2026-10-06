<?php

declare(strict_types=1);

namespace Nvl\Activity\Contracts;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Nvl\Activity\Support\ActivityRecordEnvelope;
use Nvl\Activity\Support\ActivitySubjectReference;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;

/**
 * Defines the canonical activity recording boundary used by host workflows and the facade.
 *
 * @api
 */
interface ActivityRecorderContract
{
    /** Deliver one immutable activity fact and return the same row on an identical retry. */
    public function recordEnvelope(ActivityRecordEnvelope $envelope): ActivityContract;

    /**
     * Record an activity with the canonical structured payload contract.
     *
     * @param  string|BackedEnum  $event  Canonical event key or backed enum
     * @param  string  $description  Optional machine description; defaults to the event key
     * @param  array<string, mixed>  $context  Event-specific business context
     * @param  array<string, mixed>|null  $attributes  Explicit changed attribute values
     * @param  array<string, mixed>|null  $old  Explicit previous attribute values
     * @param  Model|string|int|null  $actor  Causing actor model, scalar ID, or null
     * @param  string|BackedEnum|null  $source  Entry source classification; inferred when omitted
     * @param  string|BackedEnum|null  $visibility  Timeline visibility classification; timeline by default
     * @param  string|BackedEnum|null  $importance  Importance classification; normal by default
     * @param  bool  $resolveChanges  Whether changed attributes may be inferred automatically
     * @param  string|null  $batchUuid  Optional caller-owned batch identifier
     * @return ActivityContract|null Persisted activity or null when the event key is blank
     */
    public function record(
        ?Model $subject,
        string|BackedEnum $event,
        string $description = '',
        array $context = [],
        ?array $attributes = null,
        ?array $old = null,
        Model|string|int|null $actor = null,
        ?string $logName = null,
        string|BackedEnum|null $source = null,
        string|BackedEnum|null $visibility = null,
        string|BackedEnum|null $importance = null,
        bool $resolveChanges = true,
        ?string $batchUuid = null,
    ): ?ActivityContract;

    /**
     * Record activity for a stable subject identity without loading its model.
     *
     * @param  string|BackedEnum  $event  Canonical event key or backed enum
     * @param  array<string, mixed>  $context  Event-specific business context
     * @param  Model|string|int|null  $actor  Causing actor model, scalar ID, or null
     */
    public function recordForSubjectReference(
        ActivitySubjectReference $subject,
        string|BackedEnum $event,
        string $description = '',
        array $context = [],
        Model|string|int|null $actor = null,
        string|BackedEnum|null $importance = null,
    ): ?ActivityContract;
}
