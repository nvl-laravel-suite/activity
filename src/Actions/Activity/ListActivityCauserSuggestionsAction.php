<?php

declare(strict_types=1);

namespace Nvl\Activity\Actions\Activity;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Nvl\Activity\Data\Display\ActivityCauserSuggestion;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Support\ModelKeyIdentifierValidator;
use ReflectionClass;
use Spatie\LaravelData\DataCollection;
use Stringable;

/**
 * List historical user causers represented in the activity log.
 */
final class ListActivityCauserSuggestionsAction
{
    /**
     * Create the suggestion action with historical identifier validation.
     */
    public function __construct(
        private readonly ModelKeyIdentifierValidator $modelKeyIdentifierValidator = new ModelKeyIdentifierValidator,
    ) {}

    /**
     * Retrieve recently active historical causers, including soft-deleted models when supported.
     *
     * @return DataCollection<int, ActivityCauserSuggestion>
     */
    public function execute(?string $search = null, int $limit = 10): DataCollection
    {
        $resolvedLimit = min(50, max(1, $limit));
        $term = trim((string) $search);

        $userClass = $this->resolveCauserModelClass();
        if ($userClass === null) {
            return ActivityCauserSuggestion::collect([], DataCollection::class);
        }

        /** @var Model $user */
        $user = new $userClass;
        $userTable = $user->getTable();
        $userKey = $user->getKeyName();
        $schema = $user->getConnection()->getSchemaBuilder();

        if (! $schema->hasTable($userTable) || ! $schema->hasColumn($userTable, $userKey)) {
            return ActivityCauserSuggestion::collect([], DataCollection::class);
        }

        $searchAttributes = $this->searchAttributes($schema->getColumns($userTable));
        $searchKey = $this->searchableKeyValue($user, $term);

        if ($term !== '' && $searchAttributes === [] && $searchKey === null) {
            return ActivityCauserSuggestion::collect([], DataCollection::class);
        }

        $configuredScanLimit = config('activity.causer_suggestions.scan_limit', 5000);
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
            ->orderByDesc('latest_activity_at')
            ->limit($scanLimit)
            ->get();

        if ($latestCausers->isEmpty()) {
            return ActivityCauserSuggestion::collect([], DataCollection::class);
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
            return ActivityCauserSuggestion::collect([], DataCollection::class);
        }

        $usersQuery = $user->newQuery();

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

        return ActivityCauserSuggestion::collect($users, DataCollection::class);
    }

    /**
     * Resolve the explicitly configured causer model or the application's
     * Eloquent auth provider model without assuming a host namespace.
     *
     * @return class-string<Model>|null
     */
    private function resolveCauserModelClass(): ?string
    {
        $configuredModel = config('activity.causer_suggestions.model');

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
        $configuredAttributes = config('activity.causer_suggestions.search_attributes', ['name', 'email']);

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
