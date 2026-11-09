# Public API: nvl/activity

Generated from the package public contract. Use this reference to confirm fully qualified names, parameter order, defaults, native array shapes and return types. Read [usage](usage.md) for policy and operation semantics; declarations alone never grant authority.

Search for the relevant fully qualified service or contract and read only its matching symbol sections; this is a lookup catalog, not an instruction to load every declaration. `resolved` annotations expand imported aliases and domain type shapes. Framework inheritance and host-authored extensions remain outside this package contract.

## Symbols

- `Nvl\Activity\Contracts\ActivityAuthorization`
- `Nvl\Activity\Contracts\ActivityMapping`
- `Nvl\Activity\Contracts\ActivityTimelineSource`
- `Nvl\Activity\Contracts\ProvidesActivityHeadlinePlaceholders`
- `Nvl\Activity\Database\Factories\ActivityLogFactory`
- `Nvl\Activity\Enums\ActivityDoctorSeverity`
- `Nvl\Activity\Enums\ActivityEvent`
- `Nvl\Activity\Enums\ActivityImportance`
- `Nvl\Activity\Enums\ActivityResponseCode`
- `Nvl\Activity\Enums\ActivityVisibility`
- `Nvl\Activity\Events\ActivityLogPurgeQueued`
- `Nvl\Activity\Exceptions\ActivityConfigurationException`
- `Nvl\Activity\Exceptions\ActivityException`
- `Nvl\Activity\Exceptions\ActivityPurgeCriteriaException`
- `Nvl\Activity\Exceptions\ActivityRecordingException`
- `Nvl\Activity\Exceptions\ActivityTimelineException`
- `Nvl\Activity\Models\ActivityLog`
- `Nvl\Activity\Services\ActivityCaptureContext`
- `Nvl\Activity\Services\ActivityDiffBuilder`
- `Nvl\Activity\Services\ActivityDoctor`
- `Nvl\Activity\Services\ActivityModelRegistry`
- `Nvl\Activity\Services\ActivityPolicyRegistry`
- `Nvl\Activity\Services\ActivityPurgeDispatcher`
- `Nvl\Activity\Services\ActivityReader`
- `Nvl\Activity\Services\ActivityRecorder`
- `Nvl\Activity\Services\ActivityRelationLoader`
- `Nvl\Activity\Services\HeadlineRenderer`
- `Nvl\Activity\Services\LabelResolver`
- `Nvl\Activity\Services\MappingRegistry`
- `Nvl\Activity\Support\ActivityCaptureOptions`
- `Nvl\Activity\Support\ActivityCauserReference`
- `Nvl\Activity\Support\ActivityEventPolicy`
- `Nvl\Activity\Support\ActivityPurgeCriteria`
- `Nvl\Activity\Support\ActivityRecordEnvelope`
- `Nvl\Activity\Support\ActivitySubjectReference`
- `Nvl\Activity\Traits\HasModelActivity`

## Nvl\Activity\Contracts\ActivityAuthorization

```text
interface Nvl\Activity\Contracts\ActivityAuthorization
public abstract function authorize(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $ability, Illuminate\Database\Eloquent\Model|Nvl\Activity\Support\ActivitySubjectReference|null $subject=null): void
```

## Nvl\Activity\Contracts\ActivityMapping

```text
interface Nvl\Activity\Contracts\ActivityMapping
public abstract function captureOptions(): Nvl\Activity\Support\ActivityCaptureOptions
public abstract function entityLabel(): string
public abstract function eventDisplayValue(string $event, array $properties): ?string
@param array<string, mixed> $properties
public abstract function eventTemplates(): array
@return array<string, string>
public abstract function fieldLabel(string $key): string
public abstract function fieldValue(string $key, mixed $value): string
public abstract function logName(): string
public abstract function modelClass(): string
@return class-string
public abstract function subjectLabel(Illuminate\Database\Eloquent\Model $subject): string
```

## Nvl\Activity\Contracts\ActivityTimelineSource

```text
interface Nvl\Activity\Contracts\ActivityTimelineSource
@template TModel of Model
resolved @template TModel of Illuminate\Database\Eloquent\Model
public abstract function query(Illuminate\Database\Eloquent\Model|Nvl\Activity\Support\ActivitySubjectReference $subject): Illuminate\Database\Eloquent\Builder
@return Builder<TModel>
resolved @return Illuminate\Database\Eloquent\Builder<TModel>
template Nvl\Activity\Contracts\ActivityTimelineSource::TModel of Illuminate\Database\Eloquent\Model
public abstract function supersedes(): array
@return list<string>
```

## Nvl\Activity\Contracts\ProvidesActivityHeadlinePlaceholders

```text
interface Nvl\Activity\Contracts\ProvidesActivityHeadlinePlaceholders
public abstract function eventHeadlinePlaceholders(string $event, array $properties): array
@param array<string, mixed> $properties
@return array<string, array{type: string, text: string, causerId?: (string | int | null)}>
```

## Nvl\Activity\Database\Factories\ActivityLogFactory

```text
final class Nvl\Activity\Database\Factories\ActivityLogFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<ActivityLog>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Activity\Models\ActivityLog>
public function definition(): array
@return array<model-property<ActivityLog>, mixed>
resolved @return array<model-property<Nvl\Activity\Models\ActivityLog>, mixed>
public function forOwner(Illuminate\Database\Eloquent\Model $owner): static
public function modelName(): string
```

## Nvl\Activity\Enums\ActivityDoctorSeverity

```text
enum Nvl\Activity\Enums\ActivityDoctorSeverity implements BackedEnum, UnitEnum: string
public const Nvl\Activity\Enums\ActivityDoctorSeverity Error=Nvl\Activity\Enums\ActivityDoctorSeverity::Error="error"
public const Nvl\Activity\Enums\ActivityDoctorSeverity Warning=Nvl\Activity\Enums\ActivityDoctorSeverity::Warning="warning"
public function getLabel(): string
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Activity\Enums\ActivityEvent

```text
enum Nvl\Activity\Enums\ActivityEvent implements BackedEnum, UnitEnum: string
public const Nvl\Activity\Enums\ActivityEvent Activated=Nvl\Activity\Enums\ActivityEvent::Activated="activated"
public const Nvl\Activity\Enums\ActivityEvent Archived=Nvl\Activity\Enums\ActivityEvent::Archived="archived"
public const Nvl\Activity\Enums\ActivityEvent Assigned=Nvl\Activity\Enums\ActivityEvent::Assigned="assigned"
public const Nvl\Activity\Enums\ActivityEvent Created=Nvl\Activity\Enums\ActivityEvent::Created="created"
public const Nvl\Activity\Enums\ActivityEvent Deactivated=Nvl\Activity\Enums\ActivityEvent::Deactivated="deactivated"
public const Nvl\Activity\Enums\ActivityEvent Deleted=Nvl\Activity\Enums\ActivityEvent::Deleted="deleted"
public const Nvl\Activity\Enums\ActivityEvent DetailsUpdated=Nvl\Activity\Enums\ActivityEvent::DetailsUpdated="details_updated"
public const Nvl\Activity\Enums\ActivityEvent Disabled=Nvl\Activity\Enums\ActivityEvent::Disabled="disabled"
public const Nvl\Activity\Enums\ActivityEvent Enabled=Nvl\Activity\Enums\ActivityEvent::Enabled="enabled"
public const Nvl\Activity\Enums\ActivityEvent Resent=Nvl\Activity\Enums\ActivityEvent::Resent="resent"
public const Nvl\Activity\Enums\ActivityEvent Restored=Nvl\Activity\Enums\ActivityEvent::Restored="restored"
public const Nvl\Activity\Enums\ActivityEvent Retried=Nvl\Activity\Enums\ActivityEvent::Retried="retried"
public const Nvl\Activity\Enums\ActivityEvent Sent=Nvl\Activity\Enums\ActivityEvent::Sent="sent"
public const Nvl\Activity\Enums\ActivityEvent StatusChanged=Nvl\Activity\Enums\ActivityEvent::StatusChanged="status_changed"
public const Nvl\Activity\Enums\ActivityEvent StatusOverride=Nvl\Activity\Enums\ActivityEvent::StatusOverride="status_override"
public const Nvl\Activity\Enums\ActivityEvent StatusTransition=Nvl\Activity\Enums\ActivityEvent::StatusTransition="status_transition"
public const Nvl\Activity\Enums\ActivityEvent Triggered=Nvl\Activity\Enums\ActivityEvent::Triggered="triggered"
public const Nvl\Activity\Enums\ActivityEvent Unarchived=Nvl\Activity\Enums\ActivityEvent::Unarchived="unarchived"
public const Nvl\Activity\Enums\ActivityEvent Updated=Nvl\Activity\Enums\ActivityEvent::Updated="updated"
public const Nvl\Activity\Enums\ActivityEvent Viewed=Nvl\Activity\Enums\ActivityEvent::Viewed="viewed"
public function getLabel(): string
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Activity\Enums\ActivityImportance

```text
enum Nvl\Activity\Enums\ActivityImportance implements BackedEnum, UnitEnum: string
public const Nvl\Activity\Enums\ActivityImportance Important=Nvl\Activity\Enums\ActivityImportance::Important="important"
public const Nvl\Activity\Enums\ActivityImportance Low=Nvl\Activity\Enums\ActivityImportance::Low="low"
public const Nvl\Activity\Enums\ActivityImportance Normal=Nvl\Activity\Enums\ActivityImportance::Normal="normal"
public function getLabel(): string
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Activity\Enums\ActivityResponseCode

```text
enum Nvl\Activity\Enums\ActivityResponseCode implements BackedEnum, Nvl\Support\Contracts\ResponseCode, UnitEnum: string
public const Nvl\Activity\Enums\ActivityResponseCode ConflictingActivityEnvelope=Nvl\Activity\Enums\ActivityResponseCode::ConflictingActivityEnvelope="conflicting_activity_envelope"
public const Nvl\Activity\Enums\ActivityResponseCode InvalidActivityMetadata=Nvl\Activity\Enums\ActivityResponseCode::InvalidActivityMetadata="invalid_activity_metadata"
public const Nvl\Activity\Enums\ActivityResponseCode InvalidBatchIdentifier=Nvl\Activity\Enums\ActivityResponseCode::InvalidBatchIdentifier="invalid_batch_identifier"
public const Nvl\Activity\Enums\ActivityResponseCode InvalidConfiguration=Nvl\Activity\Enums\ActivityResponseCode::InvalidConfiguration="invalid_configuration"
public const Nvl\Activity\Enums\ActivityResponseCode InvalidMapping=Nvl\Activity\Enums\ActivityResponseCode::InvalidMapping="invalid_mapping"
public const Nvl\Activity\Enums\ActivityResponseCode InvalidPurgeCriteria=Nvl\Activity\Enums\ActivityResponseCode::InvalidPurgeCriteria="invalid_purge_criteria"
public const Nvl\Activity\Enums\ActivityResponseCode OperationFailed=Nvl\Activity\Enums\ActivityResponseCode::OperationFailed="operation_failed"
public const Nvl\Activity\Enums\ActivityResponseCode PurgeQueued=Nvl\Activity\Enums\ActivityResponseCode::PurgeQueued="purge_queued"
public const Nvl\Activity\Enums\ActivityResponseCode PurgeSystemQueued=Nvl\Activity\Enums\ActivityResponseCode::PurgeSystemQueued="purge_system_queued"
public const Nvl\Activity\Enums\ActivityResponseCode TimelineSubjectNotFound=Nvl\Activity\Enums\ActivityResponseCode::TimelineSubjectNotFound="timeline_subject_not_found"
public function getMessage(): string
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Activity\Enums\ActivityVisibility

```text
enum Nvl\Activity\Enums\ActivityVisibility implements BackedEnum, UnitEnum: string
public const Nvl\Activity\Enums\ActivityVisibility AuditOnly=Nvl\Activity\Enums\ActivityVisibility::AuditOnly="audit_only"
public const Nvl\Activity\Enums\ActivityVisibility Hidden=Nvl\Activity\Enums\ActivityVisibility::Hidden="hidden"
public const Nvl\Activity\Enums\ActivityVisibility Timeline=Nvl\Activity\Enums\ActivityVisibility::Timeline="timeline"
public function getLabel(): string
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Activity\Events\ActivityLogPurgeQueued

```text
final class Nvl\Activity\Events\ActivityLogPurgeQueued implements Nvl\Support\Contracts\DomainEvent, Nvl\Support\Ownership\Contracts\OwnershipQueuedJob
public function __construct(int $days, bool $systemOnly, bool $includeImportant=false, int $schemaVersion=1): mixed
public final function ownershipJobEnvelope(): Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope
public final function ownershipLockKey(string $identity): string
public function schemaVersion(): int
public final function uniqueId(): string
public readonly int $days
public readonly bool $includeImportant
public readonly int $schemaVersion
public readonly bool $systemOnly
```

## Nvl\Activity\Exceptions\ActivityConfigurationException

```text
final class Nvl\Activity\Exceptions\ActivityConfigurationException extends Nvl\Activity\Exceptions\ActivityException
public function __construct(string $message="", ?Nvl\Support\Contracts\ResponseCode $responseCode=null, int $suggestedStatus=422, array $publicContext=[], array $diagnosticContext=[], ?Throwable $previous=null): mixed
@param string $message
@param (ResponseCode | null) $responseCode
resolved @param (Nvl\Support\Contracts\ResponseCode | null) $responseCode
@param int $suggestedStatus
@param array<string, mixed> $publicContext
@param array<string, mixed> $diagnosticContext
@param (Throwable | null) $previous
@throws SupportException
resolved @throws Nvl\Support\Exceptions\SupportException
public function context(): array
@return array<string, mixed>
public static function duplicateMapping(string $mappingClass, string $modelClass): self
public static function emptyMappingLogName(string $mappingClass): self
public static function emptyTableName(): self
public static function invalidConnectionName(): self
public static function invalidMappingModel(string $mappingClass, string $modelClass): self
public static function nonEloquentActivityModel(): self
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
@return (string | null)
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
@return int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Activity\Exceptions\ActivityException

```text
class Nvl\Activity\Exceptions\ActivityException extends Nvl\Support\Exceptions\BusinessException
public function __construct(string $message="", ?Nvl\Support\Contracts\ResponseCode $responseCode=null, int $suggestedStatus=422, array $publicContext=[], array $diagnosticContext=[], ?Throwable $previous=null): mixed
@param string $message
@param (ResponseCode | null) $responseCode
resolved @param (Nvl\Support\Contracts\ResponseCode | null) $responseCode
@param int $suggestedStatus
@param array<string, mixed> $publicContext
@param array<string, mixed> $diagnosticContext
@param (Throwable | null) $previous
@throws SupportException
resolved @throws Nvl\Support\Exceptions\SupportException
public function context(): array
@return array<string, mixed>
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
@return (string | null)
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
@return int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Activity\Exceptions\ActivityPurgeCriteriaException

```text
final class Nvl\Activity\Exceptions\ActivityPurgeCriteriaException extends Nvl\Activity\Exceptions\ActivityException implements Illuminate\Contracts\Debug\ShouldntReport
public function __construct(string $message="", ?Nvl\Support\Contracts\ResponseCode $responseCode=null, int $suggestedStatus=422, array $publicContext=[], array $diagnosticContext=[], ?Throwable $previous=null): mixed
@param string $message
@param (ResponseCode | null) $responseCode
resolved @param (Nvl\Support\Contracts\ResponseCode | null) $responseCode
@param int $suggestedStatus
@param array<string, mixed> $publicContext
@param array<string, mixed> $diagnosticContext
@param (Throwable | null) $previous
@throws SupportException
resolved @throws Nvl\Support\Exceptions\SupportException
public function context(): array
@return array<string, mixed>
public static function invalidDate(string $option, Throwable $previous): self
public static function invalidRange(): self
public static function missingCutoff(): self
public static function mutuallyExclusiveCutoffs(): self
public function package(): string
public static function positiveDaysRequired(): self
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
@return (string | null)
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
@return int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
public static function unresolvedCutoff(): self
```

## Nvl\Activity\Exceptions\ActivityRecordingException

```text
final class Nvl\Activity\Exceptions\ActivityRecordingException extends Nvl\Activity\Exceptions\ActivityException
public function __construct(string $message="", ?Nvl\Support\Contracts\ResponseCode $responseCode=null, int $suggestedStatus=422, array $publicContext=[], array $diagnosticContext=[], ?Throwable $previous=null): mixed
@param string $message
@param (ResponseCode | null) $responseCode
resolved @param (Nvl\Support\Contracts\ResponseCode | null) $responseCode
@param int $suggestedStatus
@param array<string, mixed> $publicContext
@param array<string, mixed> $diagnosticContext
@param (Throwable | null) $previous
@throws SupportException
resolved @throws Nvl\Support\Exceptions\SupportException
public static function conflictingEnvelope(): self
public function context(): array
@return array<string, mixed>
public static function invalidBatchIdentifier(): self
public static function invalidMetadata(string $field): self
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
@return (string | null)
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
@return int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Activity\Exceptions\ActivityTimelineException

```text
final class Nvl\Activity\Exceptions\ActivityTimelineException extends Nvl\Activity\Exceptions\ActivityException implements Illuminate\Contracts\Debug\ShouldntReport
public function __construct(string $message="", ?Nvl\Support\Contracts\ResponseCode $responseCode=null, int $suggestedStatus=422, array $publicContext=[], array $diagnosticContext=[], ?Throwable $previous=null): mixed
@param string $message
@param (ResponseCode | null) $responseCode
resolved @param (Nvl\Support\Contracts\ResponseCode | null) $responseCode
@param int $suggestedStatus
@param array<string, mixed> $publicContext
@param array<string, mixed> $diagnosticContext
@param (Throwable | null) $previous
@throws SupportException
resolved @throws Nvl\Support\Exceptions\SupportException
public function context(): array
@return array<string, mixed>
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
@return (string | null)
public function responseHeaders(): array
@return array<string, string>
public static function subjectNotFound(string $modelClass, string $subjectId): self
public function suggestedStatus(): int
@return int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Activity\Models\ActivityLog

```text
class Nvl\Activity\Models\ActivityLog extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public const string DEFAULT_TABLE=\Nvl\Activity\Definitions\Tables\ActivityTables::ActivityLog
public function causer(): Illuminate\Database\Eloquent\Relations\MorphTo
@return ActivityMorphTo<$this>
resolved @return Nvl\Activity\Ownership\ActivityMorphTo<$this>
public function delete(): mixed
@return ($this is Pivot ? (bool | int | null) : (bool | null))
resolved @return ($this is Illuminate\Database\Eloquent\Relations\Pivot ? (bool | int | null) : (bool | null))
public static function forDisplay(): Nvl\Activity\Builders\ActivityLogBuilder
public static function forSubjectTimeline(string $subjectType, string|int|array $subjectId): Nvl\Activity\Builders\ActivityLogBuilder
@param (string | int | list<(string | int)>) $subjectId
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
#[Override()]
public function getRelationValue(mixed $key): mixed
public function getTable(): string
public function newEloquentBuilder(mixed $query): Nvl\Activity\Builders\ActivityLogBuilder
@param QueryBuilder $query
resolved @param Illuminate\Database\Query\Builder $query
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Activity\Models\ActivityLog>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
public function semanticEvent(): string
#[Override()]
public function setRelation(mixed $relation, mixed $value): mixed
public function subject(): Illuminate\Database\Eloquent\Relations\MorphTo
@return ActivityMorphTo<$this>
resolved @return Nvl\Activity\Ownership\ActivityMorphTo<$this>
public mixed $incrementing=false
@var bool
@method static ActivityLogBuilder query()
resolved @method static Nvl\Activity\Builders\ActivityLogBuilder query()
@property (Collection<(int | string), mixed> | null) $attribute_changes
resolved @property (Illuminate\Support\Collection<(int | string), mixed> | null) $attribute_changes
@property (string | null) $batch_uuid
@property (string | null) $causer_id
@property (string | null) $causer_type
@property (Carbon | null) $created_at
resolved @property (Illuminate\Support\Carbon | null) $created_at
@property string $description
@property (string | null) $event
@property string $id
@property (string | null) $log_name
@property (string | null) $owner_reference
@property string $owner_scope_key
@property (Collection<(int | string), mixed> | null) $properties
resolved @property (Illuminate\Support\Collection<(int | string), mixed> | null) $properties
@property (string | null) $subject_id
@property (string | null) $subject_type
@property (Carbon | null) $updated_at
resolved @property (Illuminate\Support\Carbon | null) $updated_at
```

## Nvl\Activity\Services\ActivityCaptureContext

```text
final class Nvl\Activity\Services\ActivityCaptureContext
public function actor(): Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor
public function run(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, callable $operation): mixed
@template T
@param callable(): T $operation
@return T
template Nvl\Activity\Services\ActivityCaptureContext::T
```

## Nvl\Activity\Services\ActivityDiffBuilder

```text
final class Nvl\Activity\Services\ActivityDiffBuilder
public function __construct(Nvl\Activity\Services\LabelResolver $labelResolver, Nvl\Activity\Services\HeadlineRenderer $headlineRenderer): mixed
public function build(Nvl\Activity\Models\ActivityLog $activity, array $properties): Illuminate\Support\Collection
@param array<string, mixed> $properties
@return Collection<int, array{key: string, label: string, old: ?string, new: ?string, description: string}>
resolved @return Illuminate\Support\Collection<int, array{key: string, label: string, old: ?string, new: ?string, description: string}>
```

## Nvl\Activity\Services\ActivityDoctor

```text
final class Nvl\Activity\Services\ActivityDoctor
public function inspect(): array
@return list<array{key: string, severity: ActivityDoctorSeverity, passed: bool, message: string}>
resolved @return list<array{key: string, severity: Nvl\Activity\Enums\ActivityDoctorSeverity, passed: bool, message: string}>
```

## Nvl\Activity\Services\ActivityModelRegistry

```text
final class Nvl\Activity\Services\ActivityModelRegistry
public function __construct(Illuminate\Contracts\Config\Repository $configuration, Nvl\Support\OwnerRegistry $identities): mixed
public function activityLogClass(): string
@return class-string<ActivityLog>
resolved @return class-string<Nvl\Activity\Models\ActivityLog>
```

## Nvl\Activity\Services\ActivityPolicyRegistry

```text
final class Nvl\Activity\Services\ActivityPolicyRegistry
public function all(): array
@return array<string, ActivityEventPolicy>
resolved @return array<string, Nvl\Activity\Support\ActivityEventPolicy>
public function get(string $event): Nvl\Activity\Support\ActivityEventPolicy
public function register(Nvl\Activity\Support\ActivityEventPolicy $policy): void
```

## Nvl\Activity\Services\ActivityPurgeDispatcher

```text
final readonly class Nvl\Activity\Services\ActivityPurgeDispatcher
public function __construct(Nvl\Support\Ownership\Contracts\OwnershipContext $ownership, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\Activity\Services\ActivityAccess $access, Nvl\Support\Events\DomainEventDispatcher $events): mixed
public function count(Nvl\Activity\Support\ActivityPurgeCriteria $criteria, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): int
public function dispatch(Nvl\Activity\Support\ActivityPurgeCriteria $criteria, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): int
```

## Nvl\Activity\Services\ActivityReader

```text
final readonly class Nvl\Activity\Services\ActivityReader
public function __construct(Nvl\Activity\Ownership\ActivityOwnershipGuard $ownership, Nvl\Activity\Services\ActivityAccess $access, Nvl\Activity\Services\ActivityRelationLoader $relations, Nvl\Activity\Services\TimelineFilter $timelineFilter, Nvl\Activity\Support\ModelKeyIdentifierValidator $modelKeyIdentifierValidator, Nvl\Activity\Services\ActivityPolicyRegistry $policies): mixed
public function causers(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, ?string $search=null, int $limit=10): Illuminate\Database\Eloquent\Collection
@return EloquentCollection<int, Model>
resolved @return Illuminate\Database\Eloquent\Collection<int, Illuminate\Database\Eloquent\Model>
public function cursor(array $filters, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, ?string $cursor=null): array
@param array<string, mixed> $filters
@return array{items: EloquentCollection<int, ActivityLog>, next_cursor: ?string}
resolved @return array{items: Illuminate\Database\Eloquent\Collection<int, Nvl\Activity\Models\ActivityLog>, next_cursor: ?string}
public function find(string $id, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): ?Nvl\Activity\Models\ActivityLog
public function paginate(array $filters, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Illuminate\Pagination\LengthAwarePaginator
@param array{search?: (string | null), event?: (string | null), events?: list<string>, causer_id?: (string | int | null), subject_type?: (string | null), subject_id?: (string | int | null), subjects?: list<ActivitySubjectReference>, subject_or_causer?: ActivitySubjectReference, created_at_from?: (DateTimeInterface | string | null), created_at_to?: (DateTimeInterface | string | null), per_page?: int, page?: int} $filters
resolved @param array{search?: (string | null), event?: (string | null), events?: list<string>, causer_id?: (string | int | null), subject_type?: (string | null), subject_id?: (string | int | null), subjects?: list<Nvl\Activity\Support\ActivitySubjectReference>, subject_or_causer?: Nvl\Activity\Support\ActivitySubjectReference, created_at_from?: (DateTimeInterface | string | null), created_at_to?: (DateTimeInterface | string | null), per_page?: int, page?: int} $filters
@return LengthAwarePaginator<int, ActivityLog>
resolved @return Illuminate\Pagination\LengthAwarePaginator<int, Nvl\Activity\Models\ActivityLog>
public function timeline(Illuminate\Database\Eloquent\Model|Nvl\Activity\Support\ActivitySubjectReference $subject, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, int $limit=100, array $sources=[]): Illuminate\Support\Collection
@template TModel of Model
resolved @template TModel of Illuminate\Database\Eloquent\Model
@param list<ActivityTimelineSource<TModel>> $sources
resolved @param list<Nvl\Activity\Contracts\ActivityTimelineSource<TModel>> $sources
@return Collection<int, Model>
resolved @return Illuminate\Support\Collection<int, Illuminate\Database\Eloquent\Model>
template Nvl\Activity\Services\ActivityReader::TModel of Illuminate\Database\Eloquent\Model
```

## Nvl\Activity\Services\ActivityRecorder

```text
final class Nvl\Activity\Services\ActivityRecorder
public function __construct(Nvl\Activity\Ownership\ActivityOwnershipGuard $ownership, Nvl\Activity\Services\ActivityAccess $access, Nvl\Activity\Services\ActivityPolicyRegistry $policies): mixed
public function record(array $entry, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Activity\Models\ActivityLog
@param array<string, mixed> $entry
public function recordEnvelope(Nvl\Activity\Support\ActivityRecordEnvelope $envelope): Nvl\Activity\Models\ActivityLog
```

## Nvl\Activity\Services\ActivityRelationLoader

```text
final class Nvl\Activity\Services\ActivityRelationLoader
public function __construct(Nvl\Activity\Support\ModelKeyIdentifierValidator $modelKeyIdentifierValidator, Nvl\Activity\Ownership\ActivityOwnershipGuard $ownership): mixed
public function load(Illuminate\Database\Eloquent\Collection $activities): void
@param EloquentCollection<int, ActivityLog> $activities
resolved @param Illuminate\Database\Eloquent\Collection<int, Nvl\Activity\Models\ActivityLog> $activities
```

## Nvl\Activity\Services\HeadlineRenderer

```text
final class Nvl\Activity\Services\HeadlineRenderer
public function __construct(Nvl\Activity\Services\LabelResolver $labelResolver): mixed
public function buildChangedText(string $attribute, ?string $old, ?string $new): string
@param string $attribute
@param (string | null) $old
@param (string | null) $new
@return string
public function buildSummary(int $changeCount, ?string $firstAttributeLabel): string
@param int $changeCount
@param (string | null) $firstAttributeLabel
@return string
public function resolveHeadline(string $event, Nvl\Activity\Models\ActivityLog $activity, string $actorName, string|int|null $causerId, Illuminate\Support\Collection $changeDetails): string
@param string $event
@param Activity $activity
resolved @param Nvl\Activity\Models\ActivityLog $activity
@param string $actorName
@param (string | int | null) $causerId
@param Collection<int, array{key: string, label: string, old: ?string, new: ?string, description: string}> $changeDetails
resolved @param Illuminate\Support\Collection<int, array{key: string, label: string, old: ?string, new: ?string, description: string}> $changeDetails
@return string
```

## Nvl\Activity\Services\LabelResolver

```text
final class Nvl\Activity\Services\LabelResolver
public function __construct(Nvl\Activity\Services\MappingRegistry $mappingRegistry): mixed
public function resolveEntityTypeLabel(Nvl\Activity\Models\ActivityLog $activity): string
@param Activity $activity
resolved @param Nvl\Activity\Models\ActivityLog $activity
@return string
public function resolveEventDisplayValue(string $event, Nvl\Activity\Models\ActivityLog $activity): ?string
@param string $event
@param Activity $activity
resolved @param Nvl\Activity\Models\ActivityLog $activity
@return (string | null)
public function resolveEventHeadlinePlaceholders(string $event, Nvl\Activity\Models\ActivityLog $activity): array
@return array<string, array{type: string, text: string, causerId?: (string | int | null)}>
public function resolveEventLabel(string $event, Nvl\Activity\Models\ActivityLog $activity): string
@param string $event
@param Activity $activity
resolved @param Nvl\Activity\Models\ActivityLog $activity
@return string
public function resolveFieldLabel(string $field, Nvl\Activity\Models\ActivityLog $activity): string
@param string $field
@param Activity $activity
resolved @param Nvl\Activity\Models\ActivityLog $activity
@return string
public function resolveFieldValue(string $field, mixed $value, Nvl\Activity\Models\ActivityLog $activity): mixed
@param string $field
@param mixed $value
@param Activity $activity
resolved @param Nvl\Activity\Models\ActivityLog $activity
@return mixed
public function resolveModuleEventTemplate(string $event, Nvl\Activity\Models\ActivityLog $activity): ?string
@param string $event
@param Activity $activity
resolved @param Nvl\Activity\Models\ActivityLog $activity
@return (string | null)
public function resolveNewStatusLabel(Nvl\Activity\Models\ActivityLog $activity): ?string
@param Activity $activity
resolved @param Nvl\Activity\Models\ActivityLog $activity
@return (string | null)
public function resolveSubjectLabel(Nvl\Activity\Models\ActivityLog $activity): string
@param Activity $activity
resolved @param Nvl\Activity\Models\ActivityLog $activity
@return string
```

## Nvl\Activity\Services\MappingRegistry

```text
final class Nvl\Activity\Services\MappingRegistry
public function all(): array
@return array<class-string, ActivityMapping>
resolved @return array<class-string, Nvl\Activity\Contracts\ActivityMapping>
public function forModel(string $modelClass): ?Nvl\Activity\Contracts\ActivityMapping
@param class-string $modelClass
@return (ActivityMapping | null)
resolved @return (Nvl\Activity\Contracts\ActivityMapping | null)
public function has(string $modelClass): bool
@param class-string $modelClass
@return bool
public function register(Nvl\Activity\Contracts\ActivityMapping $mapping): void
@param ActivityMapping $mapping
resolved @param Nvl\Activity\Contracts\ActivityMapping $mapping
```

## Nvl\Activity\Support\ActivityCaptureOptions

```text
final readonly class Nvl\Activity\Support\ActivityCaptureOptions
public function __construct(array $fields, array $events=['created', 'updated', 'deleted', 'restored'], bool $onlyDirty=true): mixed
@param array<array-key, mixed> $fields
@param array<array-key, mixed> $events
public readonly array $events
@var list<string>
public readonly array $fields
@var list<string>
public readonly bool $onlyDirty
```

## Nvl\Activity\Support\ActivityCauserReference

```text
final readonly class Nvl\Activity\Support\ActivityCauserReference
public function __construct(string $type, string|int $id): mixed
public readonly string|int $id
public readonly string $type
```

## Nvl\Activity\Support\ActivityEventPolicy

```text
final readonly class Nvl\Activity\Support\ActivityEventPolicy
public function __construct(string $event, ?array $subjectTypes=null, string $actor="optional", ?array $actorTypes=null, ?array $changeFields=[], ?array $metadataFields=[], array $redactedFields=[], ?int $retentionDays=365, string $audience="authorized"): mixed
@param (array<array-key, mixed> | null) $subjectTypes
@param (array<array-key, mixed> | null) $actorTypes
@param (array<array-key, mixed> | null) $changeFields
@param (array<array-key, mixed> | null) $metadataFields
@param array<array-key, mixed> $redactedFields
public readonly string $actor
public readonly ?array $actorTypes
@var (list<string> | null)
public readonly string $audience
public readonly ?array $changeFields
@var (list<string> | null)
public readonly string $event
public readonly ?array $metadataFields
@var (list<string> | null)
public readonly array $redactedFields
@var list<string>
public readonly ?int $retentionDays
public readonly ?array $subjectTypes
@var (list<string> | null)
```

## Nvl\Activity\Support\ActivityPurgeCriteria

```text
final readonly class Nvl\Activity\Support\ActivityPurgeCriteria
public function __construct(?int $days=null, ?string $before=null, ?string $after=null, bool $systemOnly=false, bool $includeImportant=false, array $events=[], array $logNames=[], array $subjectTypes=[], array $subjectIds=[], array $causerTypes=[], array $causerIds=[]): mixed
@param list<string> $events
@param list<string> $logNames
@param list<string> $subjectTypes
@param list<string> $subjectIds
@param list<string> $causerTypes
@param list<string> $causerIds
public function afterCutoff(): ?Carbon\CarbonImmutable
public function cutoff(): Carbon\CarbonImmutable
public static function fromConsoleOptions(array $options, bool $forceSystemOnly=false, ?int $defaultDays=null): self
@param array<string, mixed> $options
public static function fromDays(int $days, bool $systemOnly=false, bool $includeImportant=false): self
public function summaryParts(): array
@return list<string>
public readonly ?string $after
public readonly ?string $before
public readonly array $causerIds
@param list<string> $causerIds
public readonly array $causerTypes
@param list<string> $causerTypes
public readonly ?int $days
public readonly array $events
@param list<string> $events
public readonly bool $includeImportant
public readonly array $logNames
@param list<string> $logNames
public readonly array $subjectIds
@param list<string> $subjectIds
public readonly array $subjectTypes
@param list<string> $subjectTypes
public readonly bool $systemOnly
```

## Nvl\Activity\Support\ActivityRecordEnvelope

```text
final readonly class Nvl\Activity\Support\ActivityRecordEnvelope
public function __construct(string $id, Nvl\Activity\Support\ActivitySubjectReference $subject, ?Nvl\Activity\Support\ActivityCauserReference $causer, string $event, string $logName, Carbon\CarbonImmutable $occurredAt, array $context=[], ?array $attributes=null, ?array $old=null, ?string $scalarActorId=null): mixed
@param array<string, mixed> $context
@param (array<string, mixed> | null) $attributes
@param (array<string, mixed> | null) $old
public static function fromArray(array $payload): self
@param array<string, mixed> $payload
public function toArray(): array
@return array{id: string, subject: array{type: string, id: (string | int)}, causer: (array{type: string, id: (string | int)} | null), event: string, logName: string, occurredAt: string, context: array<string, mixed>, attributes: (array<string, mixed> | null), old: (array<string, mixed> | null), scalarActorId: (string | null)}
public readonly ?array $attributes
@param (array<string, mixed> | null) $attributes
public readonly ?Nvl\Activity\Support\ActivityCauserReference $causer
public readonly array $context
@param array<string, mixed> $context
public readonly string $event
public readonly string $id
public readonly string $logName
public readonly Carbon\CarbonImmutable $occurredAt
public readonly ?array $old
@param (array<string, mixed> | null) $old
public readonly ?string $scalarActorId
public readonly Nvl\Activity\Support\ActivitySubjectReference $subject
```

## Nvl\Activity\Support\ActivitySubjectReference

```text
final readonly class Nvl\Activity\Support\ActivitySubjectReference
public function __construct(string $type, string|int $id): mixed
public readonly string|int $id
public readonly string $type
```

## Nvl\Activity\Traits\HasModelActivity

```text
trait Nvl\Activity\Traits\HasModelActivity
@mixin Model
resolved @mixin Illuminate\Database\Eloquent\Model
public function activitiesAsSubject(): Illuminate\Database\Eloquent\Relations\MorphMany
@return MorphMany<ActivityLog, $this>
resolved @return Illuminate\Database\Eloquent\Relations\MorphMany<Nvl\Activity\Models\ActivityLog, $this>
```
