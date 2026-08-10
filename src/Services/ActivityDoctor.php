<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Composer\InstalledVersions;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Contracts\MergesActivity;
use Nvl\Activity\Data\ActivityDoctorCheckData;
use Nvl\Activity\Enums\ActivityDoctorSeverity;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Models\ActivityLog;
use ReflectionClass;
use Throwable;

/**
 * Validates the configured activity schema and runtime adoption contract.
 */
final class ActivityDoctor
{
    /** @var list<string> */
    private const array NON_DURABLE_QUEUE_DRIVERS = [
        'background',
        'deferred',
        'null',
        'sync',
    ];

    /**
     * Create the readiness inspector with the application's authorization registry.
     */
    public function __construct(
        private readonly Gate $gate,
    ) {}

    /**
     * Inspect every non-mutating package readiness requirement.
     *
     * @return list<ActivityDoctorCheckData>
     */
    public function inspect(): array
    {
        try {
            $model = new ActivityLog;
            $schema = Schema::connection($model->getConnectionName());
            $driver = $model->getConnection()->getDriverName();
            $table = $model->getTable();
            $exists = $schema->hasTable($table);

            $schemaChecks = [
                $this->connectionCheck(true),
                $this->tableCheck($table, $exists),
                $this->columnCheck($schema, $table, $exists),
                $this->identifierCheck($schema, $table, $exists),
                $this->jsonCheck($schema, $table, $exists, $driver),
                $this->indexCheck($schema, $table, $exists),
            ];
        } catch (Throwable) {
            $schemaChecks = [$this->connectionCheck(false)];
        }

        return [
            ...$schemaChecks,
            $this->configurationCheck(),
            $this->migrationOwnershipCheck(),
            $this->activityModelCheck(),
            $this->spatieVersionCheck(),
            $this->routeCheck(),
            $this->queueCheck(),
            $this->cacheLockCheck(),
            $this->scheduleCheck(),
        ];
    }

    /**
     * Warn when automatic vendor loading overlaps a published migration copy.
     */
    private function migrationOwnershipCheck(): ActivityDoctorCheckData
    {
        $duplicates = config('activity.migrations.enabled') === true
            ? $this->publishedMigrationDuplicates(dirname(__DIR__, 2).'/database/migrations')
            : [];

        return new ActivityDoctorCheckData(
            key: 'migrations.ownership',
            severity: ActivityDoctorSeverity::Warning,
            passed: $duplicates === [],
            message: $this->translated(
                $duplicates === [] ? 'migration_ownership_clear' : 'migration_ownership_conflict',
                ['migrations' => implode(', ', $duplicates)],
            ),
        );
    }

    /**
     * Find host migrations whose timestamp-independent names match package migrations.
     *
     * @return list<string>
     */
    private function publishedMigrationDuplicates(string $packagePath): array
    {
        $packageMigrations = glob($packagePath.'/*.php') ?: [];
        $hostMigrations = glob(database_path('migrations/*.php')) ?: [];
        $packageNames = array_map($this->migrationName(...), $packageMigrations);
        $duplicates = [];

        foreach ($hostMigrations as $migration) {
            $name = $this->migrationName($migration);

            if (in_array($name, $packageNames, true)) {
                $duplicates[] = $name;
            }
        }

        sort($duplicates);

        return array_values(array_unique($duplicates));
    }

    /**
     * Remove Laravel's timestamp prefix from a migration filename.
     */
    private function migrationName(string $path): string
    {
        return (string) preg_replace(
            '/^\d{4}_\d{2}_\d{2}_\d{6}_/',
            '',
            pathinfo($path, PATHINFO_FILENAME),
        );
    }

    /**
     * Validate strict package switches and storage identity values.
     */
    private function configurationCheck(): ActivityDoctorCheckData
    {
        $connection = config('activity.storage.connection');
        $table = config('activity.storage.table');
        $migrationsEnabled = config('activity.migrations.enabled');
        $externalVisibilityTimeout = config(
            'activity.retention.external_visibility_timeout_seconds',
        );
        $usesCanonicalManagedStorage = $migrationsEnabled !== true
            || ($connection === null && $table === ActivityLog::DEFAULT_TABLE);
        $passed = is_bool($migrationsEnabled)
            && is_bool(config('activity.routes.enabled'))
            && is_bool(config('activity.retention.schedule.enabled'))
            && ($externalVisibilityTimeout === null
                || (is_int($externalVisibilityTimeout) && $externalVisibilityTimeout > 0))
            && is_string($table)
            && trim($table) !== ''
            && ($connection === null || (is_string($connection) && trim($connection) !== ''))
            && $usesCanonicalManagedStorage;

        return new ActivityDoctorCheckData(
            key: 'configuration.values',
            severity: ActivityDoctorSeverity::Error,
            passed: $passed,
            message: $this->translated(
                $passed ? 'configuration_values_valid' : 'configuration_values_invalid',
            ),
        );
    }

    /**
     * Report whether the configured activity storage connection can be inspected safely.
     */
    private function connectionCheck(bool $available): ActivityDoctorCheckData
    {
        return new ActivityDoctorCheckData(
            key: 'schema.connection',
            severity: ActivityDoctorSeverity::Error,
            passed: $available,
            message: $this->translated(
                $available ? 'schema_connection_available' : 'schema_connection_unavailable',
            ),
        );
    }

    /**
     * Validate the configured activity table exists.
     */
    private function tableCheck(string $table, bool $exists): ActivityDoctorCheckData
    {
        return new ActivityDoctorCheckData(
            key: 'schema.table',
            severity: ActivityDoctorSeverity::Error,
            passed: $exists,
            message: $this->translated(
                $exists ? 'schema_table_exists' : 'schema_table_missing',
                ['table' => $table],
            ),
        );
    }

    /**
     * Validate required columns for the installed Spatie major version.
     */
    private function columnCheck(Builder $schema, string $table, bool $exists): ActivityDoctorCheckData
    {
        $columns = [
            'id',
            'log_name',
            'description',
            'subject_type',
            'subject_id',
            'event',
            'causer_type',
            'causer_id',
            'properties',
            'batch_uuid',
            'created_at',
            'updated_at',
        ];

        if ($this->spatieMajorVersion() >= 5) {
            $columns[] = 'attribute_changes';
        }

        $missing = $exists
            ? array_values(array_filter(
                $columns,
                static fn (string $column): bool => ! $schema->hasColumn($table, $column),
            ))
            : $columns;

        return new ActivityDoctorCheckData(
            key: 'schema.columns',
            severity: ActivityDoctorSeverity::Error,
            passed: $missing === [],
            message: $missing === []
                ? $this->translated('schema_columns_present')
                : $this->translated('schema_columns_missing', [
                    'columns' => implode(', ', $missing),
                ]),
        );
    }

    /**
     * Validate UUID primary and polymorphic identifier storage compatibility.
     */
    private function identifierCheck(Builder $schema, string $table, bool $exists): ActivityDoctorCheckData
    {
        if (! $exists || ! $this->hasColumns($schema, $table, ['id', 'subject_id', 'causer_id', 'batch_uuid'])) {
            return new ActivityDoctorCheckData(
                key: 'schema.identifiers',
                severity: ActivityDoctorSeverity::Error,
                passed: false,
                message: $this->translated('schema_identifiers_unavailable'),
            );
        }

        $columns = collect($schema->getColumns($table))->keyBy('name');
        $id = $columns->get('id');
        $subjectId = $columns->get('subject_id');
        $causerId = $columns->get('causer_id');
        $batchUuid = $columns->get('batch_uuid');
        $hasPrimaryId = collect($schema->getIndexes($table))->contains(
            static fn (array $index): bool => $index['primary'] === true
                && $index['columns'] === ['id'],
        );
        $passed = is_array($id)
            && is_array($subjectId)
            && is_array($causerId)
            && is_array($batchUuid)
            && $this->isStringColumn($id)
            && $this->isStringColumn($subjectId)
            && $this->isStringColumn($causerId)
            && $this->isStringColumn($batchUuid)
            && $id['auto_increment'] === false
            && $hasPrimaryId;

        return new ActivityDoctorCheckData(
            key: 'schema.identifiers',
            severity: ActivityDoctorSeverity::Error,
            passed: $passed,
            message: $passed
                ? $this->translated('schema_identifiers_compatible')
                : $this->translated('schema_identifiers_incompatible'),
        );
    }

    /**
     * Validate structured activity properties use JSON-compatible columns.
     */
    private function jsonCheck(
        Builder $schema,
        string $table,
        bool $exists,
        string $driver,
    ): ActivityDoctorCheckData {
        $columns = ['properties'];

        if ($this->spatieMajorVersion() >= 5) {
            $columns[] = 'attribute_changes';
        }

        if (! $exists || ! $this->hasColumns($schema, $table, $columns)) {
            return new ActivityDoctorCheckData(
                key: 'schema.json',
                severity: ActivityDoctorSeverity::Error,
                passed: false,
                message: $this->translated('schema_json_unavailable'),
            );
        }

        $compatibleTypes = $driver === 'pgsql'
            ? ['json', 'jsonb']
            : ['json', 'jsonb', 'text'];
        $invalid = array_values(array_filter(
            $columns,
            fn (string $column): bool => ! in_array(
                strtolower($schema->getColumnType($table, $column)),
                $compatibleTypes,
                true,
            ),
        ));

        return new ActivityDoctorCheckData(
            key: 'schema.json',
            severity: ActivityDoctorSeverity::Error,
            passed: $invalid === [],
            message: $invalid === []
                ? $this->translated('schema_json_compatible')
                : $this->translated('schema_json_incompatible', [
                    'columns' => implode(', ', $invalid),
                ]),
        );
    }

    /**
     * Validate indexes used by subject, causer, feed, and purge queries.
     */
    private function indexCheck(Builder $schema, string $table, bool $exists): ActivityDoctorCheckData
    {
        if (! $exists) {
            return new ActivityDoctorCheckData(
                key: 'schema.indexes',
                severity: ActivityDoctorSeverity::Warning,
                passed: false,
                message: $this->translated('schema_indexes_unavailable'),
            );
        }

        $indexes = $schema->getIndexes($table);
        $required = [
            ['subject_type', 'subject_id'],
            ['causer_type', 'causer_id'],
            ['created_at', 'id'],
            ['event', 'created_at'],
        ];
        $missing = array_values(array_filter(
            $required,
            fn (array $columns): bool => ! $this->hasIndexColumns($indexes, $columns),
        ));

        return new ActivityDoctorCheckData(
            key: 'schema.indexes',
            severity: ActivityDoctorSeverity::Warning,
            passed: $missing === [],
            message: $missing === []
                ? $this->translated('schema_indexes_present')
                : $this->translated('schema_indexes_missing', [
                    'indexes' => implode(', ', array_map(
                        static fn (array $columns): string => '('.implode(', ', $columns).')',
                        $missing,
                    )),
                ]),
        );
    }

    /**
     * Validate the configured activity model binding.
     */
    private function activityModelCheck(): ActivityDoctorCheckData
    {
        $activityModel = config('activitylog.activity_model');
        $legacyActivityModel = config('activity.model');
        $passed = $activityModel === ActivityLog::class
            && ($legacyActivityModel === null || $legacyActivityModel === ActivityLog::class);

        return new ActivityDoctorCheckData(
            key: 'binding.activity_model',
            severity: ActivityDoctorSeverity::Error,
            passed: $passed,
            message: $passed
                ? $this->translated('activity_model_compatible')
                : $this->translated('activity_model_incompatible'),
        );
    }

    /**
     * Validate the installed Spatie Activitylog major version.
     */
    private function spatieVersionCheck(): ActivityDoctorCheckData
    {
        $major = $this->spatieMajorVersion();
        $passed = in_array($major, [4, 5], true);
        $version = InstalledVersions::getPrettyVersion('spatie/laravel-activitylog') ?? 'unknown';

        return new ActivityDoctorCheckData(
            key: 'dependency.spatie_activitylog',
            severity: ActivityDoctorSeverity::Error,
            passed: $passed,
            message: $passed
                ? $this->translated('spatie_supported', ['version' => $version])
                : $this->translated('spatie_unsupported', ['version' => $version]),
        );
    }

    /**
     * Validate fail-closed route, ability, middleware, and subject configuration.
     */
    private function routeCheck(): ActivityDoctorCheckData
    {
        $enabled = config('activity.routes.enabled', false) === true;

        if (! $enabled) {
            return new ActivityDoctorCheckData(
                key: 'routes.management',
                severity: ActivityDoctorSeverity::Error,
                passed: true,
                message: $this->translated('routes_disabled'),
            );
        }

        $middleware = $this->stringList(config('activity.routes.management_middleware', []));
        $subjects = $this->stringList(config('activity.routes.timeline_subjects', []));
        $abilities = config('activity.authorization.abilities', []);
        $configuredAbilities = is_array($abilities)
            && collect(['view', 'timeline', 'purge'])->every(function (string $operation) use ($abilities): bool {
                $ability = $abilities[$operation] ?? null;

                return is_string($ability)
                    && trim($ability) !== ''
                    && $this->gate->has(trim($ability));
            });
        $passed = $middleware !== []
            && $this->hasValidTimelineSubjects($subjects)
            && $configuredAbilities;

        return new ActivityDoctorCheckData(
            key: 'routes.management',
            severity: ActivityDoctorSeverity::Error,
            passed: $passed,
            message: $passed
                ? $this->translated('routes_safe')
                : $this->translated('routes_unsafe'),
        );
    }

    /**
     * Validate queued maintenance configuration when automated entrypoints are enabled.
     */
    private function queueCheck(): ActivityDoctorCheckData
    {
        $queue = config('activity.retention.queue');
        $defaultConnection = config('queue.default');
        $connections = config('queue.connections');
        $externalVisibilityTimeout = config(
            'activity.retention.external_visibility_timeout_seconds',
        );
        $passed = is_string($queue)
            && trim($queue) !== ''
            && is_string($defaultConnection)
            && trim($defaultConnection) !== ''
            && is_array($connections)
            && $this->queueConnectionHasSafeVisibility(
                trim($defaultConnection),
                $connections,
                is_int($externalVisibilityTimeout) ? $externalVisibilityTimeout : null,
            );

        return new ActivityDoctorCheckData(
            key: 'retention.queue',
            severity: ActivityDoctorSeverity::Warning,
            passed: $passed,
            message: $passed
                ? $this->translated('queue_safe', [
                    'seconds' => PurgeActivityLogsJob::TIMEOUT_SECONDS,
                ])
                : $this->translated('queue_unsafe', [
                    'seconds' => PurgeActivityLogsJob::TIMEOUT_SECONDS,
                ]),
        );
    }

    /**
     * Validate one queue connection and every connection behind a failover driver.
     *
     * @param  array<array-key, mixed>  $connections
     * @param  list<string>  $visited
     */
    private function queueConnectionHasSafeVisibility(
        string $connectionName,
        array $connections,
        ?int $externalVisibilityTimeout,
        array $visited = [],
    ): bool {
        if ($connectionName === '' || in_array($connectionName, $visited, true)) {
            return false;
        }

        $connection = $connections[$connectionName] ?? null;

        if (! is_array($connection)) {
            return false;
        }

        $driver = $connection['driver'] ?? null;

        if (! is_string($driver) || trim($driver) === '') {
            return false;
        }

        $driver = strtolower(trim($driver));

        if (in_array($driver, self::NON_DURABLE_QUEUE_DRIVERS, true)) {
            return false;
        }

        if ($driver === 'failover') {
            $failoverConnections = $connection['connections'] ?? null;

            if (! is_array($failoverConnections)
                || ! array_is_list($failoverConnections)
                || $failoverConnections === []) {
                return false;
            }

            $visited[] = $connectionName;

            foreach ($failoverConnections as $failoverConnection) {
                if (! is_string($failoverConnection)
                    || ! $this->queueConnectionHasSafeVisibility(
                        trim($failoverConnection),
                        $connections,
                        $externalVisibilityTimeout,
                        $visited,
                    )) {
                    return false;
                }
            }

            return true;
        }

        if (array_key_exists('retry_after', $connection)) {
            $retryAfter = $connection['retry_after'];

            return is_int($retryAfter)
                && $retryAfter > PurgeActivityLogsJob::TIMEOUT_SECONDS;
        }

        return $externalVisibilityTimeout !== null
            && $externalVisibilityTimeout > PurgeActivityLogsJob::TIMEOUT_SECONDS;
    }

    /**
     * Validate that purge and scheduler locks use a production-capable cache store.
     */
    private function cacheLockCheck(): ActivityDoctorCheckData
    {
        $defaultStore = config('cache.default');
        $stores = config('cache.stores');
        $passed = is_string($defaultStore)
            && trim($defaultStore) !== ''
            && is_array($stores)
            && $this->cacheStoreHasSafeLocks(trim($defaultStore), $stores);

        return new ActivityDoctorCheckData(
            key: 'retention.cache_lock',
            severity: ActivityDoctorSeverity::Warning,
            passed: $passed,
            message: $this->translated(
                $passed ? 'cache_lock_safe' : 'cache_lock_unsafe',
            ),
        );
    }

    /**
     * Validate one canonical cache store without cross-domain failover.
     *
     * @param  array<array-key, mixed>  $stores
     */
    private function cacheStoreHasSafeLocks(
        string $storeName,
        array $stores,
    ): bool {
        if ($storeName === '') {
            return false;
        }

        $storeConfiguration = $stores[$storeName] ?? null;
        if (! is_array($storeConfiguration)) {
            return false;
        }

        $driver = $storeConfiguration['driver'] ?? null;
        if (! is_string($driver) || trim($driver) === '') {
            return false;
        }

        $driver = strtolower(trim($driver));
        if (in_array($driver, ['array', 'failover', 'null'], true)) {
            return false;
        }

        try {
            return Cache::store($storeName)->getStore() instanceof LockProvider;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Validate opt-in retention scheduling configuration.
     */
    private function scheduleCheck(): ActivityDoctorCheckData
    {
        $enabled = config('activity.retention.schedule.enabled', false) === true;

        if (! $enabled) {
            return new ActivityDoctorCheckData(
                key: 'retention.schedule',
                severity: ActivityDoctorSeverity::Warning,
                passed: true,
                message: $this->translated('schedule_disabled'),
            );
        }

        $time = config('activity.retention.schedule.time');
        $days = config('activity.retention.system_logs_days');
        $passed = is_string($time)
            && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) === 1
            && is_int($days)
            && $days > 0;

        return new ActivityDoctorCheckData(
            key: 'retention.schedule',
            severity: ActivityDoctorSeverity::Warning,
            passed: $passed,
            message: $passed
                ? $this->translated('schedule_enabled', ['time' => (string) $time])
                : $this->translated('schedule_invalid'),
        );
    }

    /**
     * Determine whether all requested columns exist.
     *
     * @param  list<string>  $columns
     */
    private function hasColumns(Builder $schema, string $table, array $columns): bool
    {
        return collect($columns)->every(
            static fn (string $column): bool => $schema->hasColumn($table, $column),
        );
    }

    /**
     * Determine whether a schema column provides string identifier storage.
     *
     * @param  array<string, mixed>  $column
     */
    private function isStringColumn(array $column): bool
    {
        $typeName = $column['type_name'] ?? null;

        if (! is_string($typeName)) {
            return false;
        }

        $type = strtolower($typeName);

        return in_array($type, ['char', 'string', 'text', 'uuid', 'varchar'], true);
    }

    /**
     * Determine whether an index starts with the required ordered columns.
     *
     * @param  list<array{name: string, columns: list<string>, type: string, unique: bool, primary: bool}>  $indexes
     * @param  list<string>  $requiredColumns
     */
    private function hasIndexColumns(array $indexes, array $requiredColumns): bool
    {
        return collect($indexes)->contains(
            static fn (array $index): bool => array_slice(
                $index['columns'],
                0,
                count($requiredColumns),
            ) === $requiredColumns,
        );
    }

    /**
     * Normalize a configuration value to a non-empty string list.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): mixed => is_string($item) ? trim($item) : $item,
            $value,
        ), static fn (mixed $item): bool => is_string($item) && $item !== ''));
    }

    /**
     * Determine whether every timeline subject resolves to an instantiable host contract.
     *
     * @param  list<string>  $subjects
     */
    private function hasValidTimelineSubjects(array $subjects): bool
    {
        if ($subjects === []) {
            return false;
        }

        return collect($subjects)->every(static function (string $subject): bool {
            $modelClass = Relation::getMorphedModel($subject) ?? $subject;

            if (! class_exists($modelClass)
                || ! is_subclass_of($modelClass, Model::class)
                || ! is_subclass_of($modelClass, MergesActivity::class)) {
                return false;
            }

            $modelReflection = new ReflectionClass($modelClass);
            $constructor = $modelReflection->getConstructor();

            return $modelReflection->isInstantiable()
                && ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0);
        });
    }

    /**
     * Resolve one localized package-doctor message.
     *
     * @param  array<string, scalar>  $replacements
     */
    private function translated(string $key, array $replacements = []): string
    {
        return (string) trans(
            "activity::activity/general.doctor.messages.{$key}",
            $replacements,
        );
    }

    /**
     * Resolve the installed Spatie Activitylog major version.
     */
    private function spatieMajorVersion(): int
    {
        $version = InstalledVersions::getVersion('spatie/laravel-activitylog');

        return is_string($version) ? (int) $version : 0;
    }
}
