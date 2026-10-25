<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Nvl\Activity\Builders\ActivityLogBuilder;
use Nvl\Activity\Contracts\ActivityTimelineSource;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Ownership\ActivityOwnershipGuard;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Activity\Support\ModelKeyIdentifierValidator;
use Nvl\Support\Enums\SystemActor;
use ReflectionClass;
use Stringable;

/**
 * Authorized native activity queries, bounded merged timelines and safe historical causers.
 *
 * @api
 */
final readonly class ActivityReader
{
    public function __construct(
        private ActivityOwnershipGuard $ownership,
        private ActivityAccess $access,
        private ActivityRelationLoader $relations,
        private TimelineFilter $timelineFilter,
        private ModelKeyIdentifierValidator $modelKeyIdentifierValidator,
    ) {}

    /**
     * All filters use snake_case; null omits an optional predicate. Unknown keys fail.
     *
     * @param array{search?: string|null,event?:string|null,events?:list<string>,causer_id?:string|int|null,
     * subject_type?:string|null,subject_id?:string|int|null,subjects?:list<ActivitySubjectReference>,
     * subject_or_causer?:ActivitySubjectReference,created_at_from?:DateTimeInterface|string|null,
     * created_at_to?:DateTimeInterface|string|null,per_page?:int,page?:int} $filters
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    public function paginate(array $filters, Authenticatable|SystemActor $actor): LengthAwarePaginator
    {
        $filters = $this->filters($filters);
        $this->access->authorize($actor, 'read');
        $query = ActivityLog::query()->newestFirst()->applyIndexFilters($filters);
        if (isset($filters['subjects'])) {
            $references = $filters['subjects'];
            if ($references === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where(function (Builder $query) use ($references): void {
                    foreach ($references as $reference) {
                        $query->orWhere(fn (Builder $pair) => $pair->where('subject_type', $reference->type)->where('subject_id', $reference->id));
                    }
                });
            }
        }
        if (isset($filters['subject_or_causer'])) {
            $reference = $filters['subject_or_causer'];
            $query->where(fn (Builder $query) => $query
                ->where(fn (Builder $subject) => $subject->where('subject_type', $reference->type)->where('subject_id', $reference->id))
                ->orWhere(fn (Builder $causer) => $causer->where('causer_type', $reference->type)->where('causer_id', $reference->id)));
        }
        $page = $query->paginate($filters['per_page'], ['*'], 'page', $filters['page']);
        $this->relations->load(new EloquentCollection($page->items()));

        return $page;
    }

    /**
     * Read at most 1000 visible records, scanning at most 5000 base facts in 100-row batches.
     * Custom sources supply subject constraints; current owner predicates are added by Activity.
     *
     * @template TModel of Model
     *
     * @param  list<ActivityTimelineSource<TModel>>  $sources
     * @return Collection<int, Model>
     */
    public function timeline(Model|ActivitySubjectReference $subject, Authenticatable|SystemActor $actor, int $limit = 100, array $sources = []): Collection
    {
        if ($limit < 1 || $limit > 1000 || count($sources) > 10) {
            throw new InvalidArgumentException('Activity timelines require 1 to 1000 rows and at most 10 sources.');
        }
        $this->access->authorize($actor, 'timeline', $subject);
        if ($subject instanceof Model) {
            $this->ownership->assertSubject($subject);
            $identifier = $subject->getKey();
            if (! $subject->exists || (! is_string($identifier) && ! is_int($identifier))) {
                throw new InvalidArgumentException('Activity timelines require persisted subjects.');
            }
            $reference = new ActivitySubjectReference($subject->getMorphClass(), $identifier);
        } else {
            $reference = $subject;
        }
        /** @var Collection<int, Model> $rows */
        $rows = new Collection;
        $suppressed = [];
        foreach ($sources as $source) {
            $records = $this->sourceRecords($source, $subject, $limit);
            if ($records->isNotEmpty()) {
                $suppressed = [...$suppressed, ...$source->supersedes()];
                $rows = $rows->concat($records);
            }
        }
        $cursor = null;
        $base = new EloquentCollection;
        for ($scanned = 0; $scanned < 5000 && $base->count() < $limit; $scanned += 100) {
            $query = ActivityLog::query()->forSubject($reference->type, $reference->id)->newestFirst();
            if ($cursor instanceof ActivityLog) {
                $this->afterCursor($query, $cursor);
            }
            $batch = $query->limit(100)->get();
            foreach ($batch as $record) {
                if (! in_array($record->event, $suppressed, true) && $this->timelineFilter->shouldIncludeInSignalTimeline($record)) {
                    $base->push($record);
                    if ($base->count() === $limit) {
                        break;
                    }
                }
            }
            $cursor = $batch->last();
            if ($batch->count() < 100) {
                break;
            }
        }
        $this->relations->load($base);

        return $rows->concat($base)->sort(function (Model $a, Model $b): int {
            $left = $a->getRawOriginal('created_at');
            $right = $b->getRawOriginal('created_at');
            $left = is_string($left) ? CarbonImmutable::parse($left)->utc()->format('Y-m-d H:i:s.u') : '';
            $right = is_string($right) ? CarbonImmutable::parse($right)->utc()->format('Y-m-d H:i:s.u') : '';

            return strcmp($right, $left) ?: $this->compareTimelineIdentity($a, $b);
        })->take($limit)->values();
    }

    /** Match each source's native primary-key SQL order within a stable morph-type order. */
    private function compareTimelineIdentity(Model $left, Model $right): int
    {
        $typeOrder = strcmp($right->getMorphClass(), $left->getMorphClass());
        if ($typeOrder !== 0) {
            return $typeOrder;
        }
        $leftKey = $this->identifier($left->getKey());
        $rightKey = $this->identifier($right->getKey());

        return $left->getKeyType() === 'int' && $right->getKeyType() === 'int'
            ? $rightKey <=> $leftKey
            : strcmp((string) $rightKey, (string) $leftKey);
    }

    /**
     * @template TModel of Model
     *
     * @param  ActivityTimelineSource<TModel>  $source
     * @return EloquentCollection<int, Model>
     */
    private function sourceRecords(ActivityTimelineSource $source, Model|ActivitySubjectReference $subject, int $limit): EloquentCollection
    {
        $query = $source->query($subject);
        if ($this->ownership->enabled()) {
            $this->ownership->relatedQuery($query);
        }
        $model = $query->getModel();

        return $query->reorder()->orderByDesc($model->qualifyColumn('created_at'))->orderByDesc($model->getQualifiedKeyName())->limit($limit)->get();
    }

    /** Apply a deterministic cursor without skipping legacy rows with null timestamps. */
    private function afterCursor(ActivityLogBuilder $query, ActivityLog $cursor): void
    {
        $date = $cursor->getRawOriginal('created_at');
        if ($date === null) {
            $query->whereNull('created_at')->where('id', '<', $cursor->getKey());
        } else {
            $query->where(fn (Builder $query) => $query->where('created_at', '<', $date)
                ->orWhere(fn (Builder $same) => $same->where('created_at', $date)->where('id', '<', $cursor->getKey()))
                ->orWhereNull('created_at'));
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{search?:string|null,event?:string|null,events:list<string>,causer_id?:string|int|null,subject_type?:string|null,subject_id?:string|int|null,subjects?:list<ActivitySubjectReference>,subject_or_causer?:ActivitySubjectReference,created_at_from?:CarbonImmutable|null,created_at_to?:CarbonImmutable|null,per_page:int,page:int}
     */
    private function filters(array $filters): array
    {
        if (array_diff(array_keys($filters), ['search', 'event', 'events', 'causer_id', 'subject_type', 'subject_id', 'subjects', 'subject_or_causer', 'created_at_from', 'created_at_to', 'per_page', 'page']) !== []) {
            throw new InvalidArgumentException('Unknown Activity filters.');
        }
        $events = $filters['events'] ?? [];
        if (! is_array($events) || ! array_is_list($events) || count($events) > 100) {
            throw new InvalidArgumentException('Activity events must be a bounded list.');
        }
        $validatedEvents = [];
        foreach ($events as $event) {
            $validated = $this->optionalText($event);
            if ($validated === null || trim($validated) === '') {
                throw new InvalidArgumentException('Activity event filters must be non-blank strings.');
            }
            $validatedEvents[] = $validated;
        }
        $from = $this->date($filters['created_at_from'] ?? null);
        $to = $this->date($filters['created_at_to'] ?? null);
        if ($from !== null && $to !== null && $from->gt($to)) {
            throw new InvalidArgumentException('Activity date range is reversed.');
        }
        $result = [
            'search' => $this->optionalText($filters['search'] ?? null),
            'event' => $this->optionalText($filters['event'] ?? null),
            'events' => $validatedEvents,
            'subject_type' => $this->optionalText($filters['subject_type'] ?? null),
            'subject_id' => $this->identifier($filters['subject_id'] ?? null),
            'causer_id' => $this->identifier($filters['causer_id'] ?? null),
            'created_at_from' => $from, 'created_at_to' => $to,
            'page' => $this->positiveInteger($filters['page'] ?? 1, PHP_INT_MAX),
            'per_page' => $this->positiveInteger($filters['per_page'] ?? 20, 100),
        ];
        if (isset($filters['subjects'])) {
            $subjects = $filters['subjects'];
            if (! is_array($subjects) || ! array_is_list($subjects) || count($subjects) > 100) {
                throw new InvalidArgumentException('Activity subjects must be a bounded list of references.');
            }
            $references = [];
            foreach ($subjects as $subject) {
                if (! $subject instanceof ActivitySubjectReference) {
                    throw new InvalidArgumentException('Activity subjects require immutable references.');
                }
                $references[] = $subject;
            }
            $result['subjects'] = $references;
        }
        if (isset($filters['subject_or_causer'])) {
            $reference = $filters['subject_or_causer'];
            if (! $reference instanceof ActivitySubjectReference) {
                throw new InvalidArgumentException('Activity subject_or_causer requires an immutable reference.');
            }
            $result['subject_or_causer'] = $reference;
        }

        return $result;
    }

    private function optionalText(mixed $value): ?string
    {
        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException('Activity text filters require strings.');
        }

        return $value;
    }

    private function identifier(mixed $value): string|int|null
    {
        if ($value !== null && ! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException('Activity identifiers must be scalar strings or integers.');
        }

        return $value;
    }

    private function positiveInteger(mixed $value, int $maximum): int
    {
        if (! is_int($value) || $value < 1 || $value > $maximum) {
            throw new InvalidArgumentException('Activity pagination values are outside their bounds.');
        }

        return $value;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        if ((! is_string($value) && ! $value instanceof DateTimeInterface) || (is_string($value) && trim($value) === '')) {
            throw new InvalidArgumentException('Activity dates require nonblank strings or DateTimeInterface.');
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('Invalid Activity date.', previous: $exception);
        }
    }

    /**
     * Retrieve recently active historical causers, including soft-deleted models when supported.
     *
     * @return EloquentCollection<int, Model>
     */
    public function causers(Authenticatable|SystemActor $actor, ?string $search = null, int $limit = 10): EloquentCollection
    {
        $this->access->authorize($actor, 'causers');
        $this->ownership->attributes();
        $resolvedLimit = min(50, max(1, $limit));
        $term = trim((string) $search);

        $userClass = $this->resolveCauserModelClass();
        if ($userClass === null) {
            return new EloquentCollection;
        }

        /** @var Model $user */
        $user = new $userClass;

        if ($this->ownership->enabled()) {
            $guardedUser = $this->ownership->relationModel($userClass, true);
            if (! $guardedUser instanceof Model) {
                return new EloquentCollection;
            }
            $user = $guardedUser;
        }

        $userTable = $user->getTable();
        $userKey = $user->getKeyName();
        $schema = $user->getConnection()->getSchemaBuilder();

        if (! $schema->hasTable($userTable) || ! $schema->hasColumn($userTable, $userKey)) {
            return new EloquentCollection;
        }

        $searchAttributes = $this->searchAttributes($schema->getColumns($userTable));
        if ($this->ownership->enabled() === true) {
            $searchAttributes = array_values(array_intersect(
                $searchAttributes,
                $this->ownership->causerColumns($user),
            ));
        }
        $searchAttributes = array_values(array_intersect($searchAttributes, $this->ownership->causerColumns($user)));
        $searchKey = $this->searchableKeyValue($user, $term);

        if ($term !== '' && $searchAttributes === [] && $searchKey === null) {
            return new EloquentCollection;
        }

        $configuredScanLimit = config('nvl-activity.causer_suggestions.scan_limit', 5000);
        $scanLimit = min(50000, max(
            $resolvedLimit,
            is_int($configuredScanLimit) ? $configuredScanLimit : 5000,
        ));

        $latestCausers = ActivityLog::query()
            ->select('causer_id')
            ->selectRaw('MAX(created_at) as latest_activity_at')
            ->where('causer_type', $user->getMorphClass())
            ->whereNotNull('causer_id')
            ->groupBy('causer_id')
            ->orderByDesc('latest_activity_at')->orderByDesc('causer_id')
            ->limit($scanLimit)
            ->get();

        if ($latestCausers->isEmpty()) {
            return new EloquentCollection;
        }

        $normalizedCauserIds = $this->modelKeyIdentifierValidator->normalizedIdentifiers(
            $user,
            array_values($latestCausers->pluck('causer_id')->all()),
        );

        /** @var array<int|string, string> $latestActivityByCauser */
        $latestActivityByCauser = [];
        /** @var array<int|string, int|string> $validCauserIdsByKey */
        $validCauserIdsByKey = [];

        foreach ($latestCausers->values() as $index => $activity) {
            $causerId = $normalizedCauserIds[$index] ?? null;
            $latestActivity = $activity->getAttribute('latest_activity_at');

            if ($causerId === null
                || (! is_string($latestActivity) && ! $latestActivity instanceof Stringable)) {
                continue;
            }

            $key = (string) $causerId;
            if (array_key_exists($key, $latestActivityByCauser)) {
                continue;
            }

            $latestActivityByCauser[$key] = (string) $latestActivity;
            $validCauserIdsByKey[$key] = $causerId;
        }

        $validCauserIds = array_values($validCauserIdsByKey);

        if ($validCauserIds === []) {
            return new EloquentCollection;
        }

        $usersQuery = $user->newQuery()->select($this->ownership->causerColumns($user));

        if ($this->ownership->enabled() === true) {
            $usersQuery = $this->ownership->relatedQuery($usersQuery);
            $usersQuery->select($this->ownership->causerColumns($user));
        }

        if (in_array(SoftDeletes::class, class_uses_recursive($userClass), true)) {
            $usersQuery->withoutGlobalScope(SoftDeletingScope::class);
        }

        $users = $usersQuery
            ->whereKey($validCauserIds)
            ->when($term !== '', function (Builder $query) use ($searchAttributes, $searchKey, $term, $userKey, $userTable): void {
                $query->where(function (Builder $searchQuery) use ($searchAttributes, $searchKey, $term, $userKey, $userTable): void {
                    foreach ($searchAttributes as $attribute) {
                        $searchQuery->getQuery()->orWhereLike("{$userTable}.{$attribute}", "%{$term}%");
                    }

                    if ($searchKey !== null) {
                        $searchQuery->getQuery()->orWhere("{$userTable}.{$userKey}", $searchKey);
                    }
                });
            })
            ->get()
            ->sortByDesc(
                static function (Model $causer) use ($latestActivityByCauser): string {
                    $key = $causer->getKey();

                    return is_string($key) || is_int($key)
                        ? ($latestActivityByCauser[(string) $key] ?? '')
                        : '';
                },
            )
            ->take($resolvedLimit)
            ->values();

        return $users;
    }

    /**
     * Resolve the explicitly configured causer model or the application's
     * Eloquent auth provider model without assuming a host namespace.
     *
     * @return class-string<Model>|null
     */
    private function resolveCauserModelClass(): ?string
    {
        $configuredModel = config('nvl-activity.causer_suggestions.model');

        if ($configuredModel === null || $configuredModel === '') {
            $configuredModel = config('auth.providers.users.model');
        }

        if (
            ! is_string($configuredModel)
            || ! class_exists($configuredModel)
            || ! is_subclass_of($configuredModel, Model::class)
        ) {
            return null;
        }

        $modelClass = new ReflectionClass($configuredModel);
        $constructor = $modelClass->getConstructor();

        if (! $modelClass->isInstantiable()
            || ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0)) {
            return null;
        }

        /** @var class-string<Model> $configuredModel */
        return $configuredModel;
    }

    /**
     * Resolve safe model attributes used by the causer search query.
     *
     * @param  list<array{name: string, type_name: string}>  $columns
     * @return list<string>
     */
    private function searchAttributes(array $columns): array
    {
        $configuredAttributes = config('nvl-activity.causer_suggestions.search_attributes', ['name', 'email']);

        if (! is_array($configuredAttributes)) {
            return [];
        }

        $availableColumns = collect($columns)->keyBy('name');

        $searchAttributes = [];

        foreach ($configuredAttributes as $attribute) {
            if (! is_string($attribute)) {
                continue;
            }

            $column = $availableColumns->get($attribute);

            if (! is_array($column)
                || ! in_array(strtolower($column['type_name']), [
                    'char',
                    'character',
                    'character varying',
                    'citext',
                    'longtext',
                    'mediumtext',
                    'nchar',
                    'nvarchar',
                    'string',
                    'text',
                    'tinytext',
                    'varchar',
                ], true)) {
                continue;
            }

            $searchAttributes[] = $attribute;
        }

        return $searchAttributes;
    }

    /**
     * Normalize an exact primary-key search without asking strict databases
     * to compare an integer key column to arbitrary text.
     */
    private function searchableKeyValue(Model $model, string $term): int|string|null
    {
        return $this->modelKeyIdentifierValidator->normalizeIdentifier($model, $term);
    }
}
