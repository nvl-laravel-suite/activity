<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use LogicException;
use Nvl\Activity\Ownership\ActivityOwnershipGuard;
use Nvl\Support\Enums\SystemActor;

/**
 * Reviews an unowned Spatie v5 audit table and runs the additive schema changes that complete it.
 *
 * Shared by the compatibility adopter and the checkpointed adoption adapter so both apply the same rules.
 *
 * @internal
 *
 * @phpstan-import-type Plan from ActivitySchemaAdopter
 *
 * @phpstan-type SchemaState array{add_batch_uuid:bool,widen_timestamps:list<string>,widen_morph_ids:list<string>,missing_indexes:array<string,list<string>>,conflicts:list<string>}
 */
final readonly class ActivitySchemaInspector
{
    /** Timestamp columns PostgreSQL adoption widens to microsecond precision. */
    public const array TIMESTAMPS = ['created_at', 'updated_at'];

    /** Morph identifier columns PostgreSQL adoption widens from native uuid to the varchar Activity compares as text. */
    public const array MORPH_IDS = ['causer_id', 'subject_id'];

    private const array REQUIRED_COLUMNS = [
        'id', 'log_name', 'description', 'subject_type', 'subject_id', 'event',
        'causer_type', 'causer_id', 'properties', 'attribute_changes', 'created_at', 'updated_at',
    ];

    public function __construct(private ActivityAccess $access, private ActivityOwnershipGuard $ownership) {}

    /**
     * Authorize the actor to adopt and refuse once native Ownership is enabled.
     */
    public function authorize(Authenticatable|SystemActor $actor): void
    {
        $this->access->authorize($actor, 'adopt');
        if ($this->ownership->enabled()) {
            throw new LogicException('Adopt legacy Activity storage before enabling native Ownership.');
        }
    }

    /** @return array{Connection,string} The configured Activity connection and table. */
    public function target(): array
    {
        $model = ActivityModelRegistry::newInstance();
        $connection = $model->getConnection();
        if (! in_array($connection->getDriverName(), ['sqlite', 'pgsql'], true)) {
            throw new LogicException('Activity adoption requires SQLite or PostgreSQL.');
        }
        $table = $model->getTable();
        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            throw new LogicException("The configured legacy Activity table [{$table}] does not exist.");
        }

        return [$connection, $table];
    }

    /**
     * Take PostgreSQL's table lock for the rest of the current transaction; SQLite serialises writers itself.
     */
    public function lock(Connection $connection, string $table): void
    {
        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement('LOCK TABLE '.$connection->getQueryGrammar()->wrapTable($table).' IN ACCESS EXCLUSIVE MODE');
        }
    }

    /**
     * Read the full table and return the reviewable plan. The fingerprint covers the historical rows and the
     * required columns, normalised so adding batch_uuid, widening timestamps and morph ids and adding indexes leave it
     * unchanged.
     *
     * @return Plan
     */
    public function review(Connection $connection, string $table): array
    {
        $schema = $connection->getSchemaBuilder();
        $columns = array_column($schema->getColumns($table), null, 'name');
        $state = $this->state($connection, $table);
        $hasBatch = ! $state['add_batch_uuid'];
        $conflicts = $state['conflicts'];

        $base = array_intersect_key($columns, array_flip(self::REQUIRED_COLUMNS));
        if ($connection->getDriverName() === 'pgsql') {
            foreach (self::TIMESTAMPS as $column) {
                $base[$column]['type'] = 'timestamp without time zone';
            }
            foreach (self::MORPH_IDS as $column) {
                unset($base[$column]['type'], $base[$column]['type_name'], $base[$column]['collation']);
            }
        }
        ksort($base);
        $hash = hash_init('sha256');
        hash_update($hash, json_encode([
            $connection->getDriverName(), $connection->getName(), $connection->getDatabaseName(),
            $connection->getConfig('host'), $connection->getConfig('port'), $connection->getConfig('search_path'),
            $connection->getTablePrefix(), $table, $base,
        ], JSON_THROW_ON_ERROR)."\n");
        $rows = 0;
        $invalidIds = 0;
        $invalidJson = 0;
        $selected = $hasBatch ? [...self::REQUIRED_COLUMNS, 'batch_uuid'] : self::REQUIRED_COLUMNS;
        foreach ($connection->table($table)->useWritePdo()->select($selected)->lazyById(500, 'id') as $row) {
            if (! is_string($row->id) || ! Str::isUuid($row->id)) {
                $invalidIds++;
            }
            $batch = $row->batch_uuid ?? null;
            if ($batch !== null && (! is_string($batch) || ! Str::isUuid($batch))) {
                $invalidIds++;
            }
            foreach ([$row->properties, $row->attribute_changes] as $json) {
                if ($json !== null && (! is_string($json) || ! json_validate($json))) {
                    $invalidJson++;
                }
            }
            hash_update($hash, json_encode([...(array) $row, 'batch_uuid' => $batch], JSON_THROW_ON_ERROR)."\n");
            $rows++;
        }
        if ($invalidIds > 0) {
            $conflicts[] = "{$invalidIds} invalid UUID values in id or batch_uuid";
        }
        if ($invalidJson > 0) {
            $conflicts[] = "{$invalidJson} invalid JSON payloads in properties or attribute_changes";
        }

        return [
            'table' => $table, 'fingerprint' => hash_final($hash), 'rows' => $rows,
            'add_batch_uuid' => $state['add_batch_uuid'], 'widen_timestamps' => $state['widen_timestamps'],
            'widen_morph_ids' => $state['widen_morph_ids'], 'missing_indexes' => $state['missing_indexes'], 'conflicts' => $conflicts,
            'ready' => $hasBatch && $state['widen_timestamps'] === [] && $state['widen_morph_ids'] === []
                && $state['missing_indexes'] === [] && $conflicts === [],
        ];
    }

    /**
     * Inspect only the schema: the outstanding additive changes and index conflicts, without reading rows.
     *
     * @return SchemaState
     */
    public function state(Connection $connection, string $table): array
    {
        $schema = $connection->getSchemaBuilder();
        $columns = array_column($schema->getColumns($table), null, 'name');
        $indexes = $schema->getIndexes($table);
        $this->assertLegacyShape($columns, $indexes, $connection->getDriverName());
        [$missing, $conflicts] = $this->planIndexes($connection, $table, $indexes);
        $widen = $morphIds = [];
        if ($connection->getDriverName() === 'pgsql') {
            foreach (self::TIMESTAMPS as $column) {
                if (preg_match('/^timestamp\([0-5]\) without time zone$/D', $columns[$column]['type']) === 1) {
                    $widen[] = $column;
                }
            }
            foreach (self::MORPH_IDS as $column) {
                if ($columns[$column]['type_name'] === 'uuid') {
                    $morphIds[] = $column;
                }
            }
        }

        return [
            'add_batch_uuid' => ! isset($columns['batch_uuid']), 'widen_timestamps' => $widen, 'widen_morph_ids' => $morphIds,
            'missing_indexes' => $missing, 'conflicts' => $conflicts,
        ];
    }

    /**
     * Return the indexes adoption guarantees, keyed by name, in creation order.
     *
     * @return array<string, list<string>>
     */
    public function requiredIndexes(Connection $connection, string $table): array
    {
        $prefix = 'nvl_activity_adopt_'.substr(hash('sha256', $table), 0, 12).'_';
        $required = [
            $prefix.'log_name' => ['log_name'],
            $prefix.'subject' => ['subject_type', 'subject_id'],
            $prefix.'causer' => ['causer_type', 'causer_id'],
            $prefix.'created' => ['created_at', 'id'],
            $prefix.'event' => ['event', 'created_at'],
        ];
        if ($connection->getDriverName() === 'pgsql') {
            $required[self::newestIndex($table)] = ['created_at', 'id'];
        }

        return $required;
    }

    /**
     * Widen the given timestamp columns to microsecond precision in one statement.
     *
     * @param  list<string>  $columns
     */
    public function widenTimestamps(Connection $connection, string $table, array $columns): void
    {
        if ($columns === []) {
            return;
        }
        $grammar = $connection->getQueryGrammar();
        $alterations = array_map(
            static fn (string $column): string => 'ALTER COLUMN '.$grammar->wrap($column).' TYPE timestamp(6) without time zone',
            $columns,
        );
        $connection->statement('ALTER TABLE '.$grammar->wrapTable($table).' '.implode(', ', $alterations));
    }

    /**
     * Convert the given PostgreSQL uuid morph id columns to the varchar(255) native Activity storage uses, so morph
     * relations and filters compare text with text, in one statement.
     *
     * @param  list<string>  $columns
     */
    public function widenMorphIds(Connection $connection, string $table, array $columns): void
    {
        if ($columns === []) {
            return;
        }
        $grammar = $connection->getQueryGrammar();
        $alterations = array_map(
            static fn (string $column): string => 'ALTER COLUMN '.$grammar->wrap($column).' TYPE varchar(255) USING '.$grammar->wrap($column).'::text',
            $columns,
        );
        $connection->statement('ALTER TABLE '.$grammar->wrapTable($table).' '.implode(', ', $alterations));
    }

    /**
     * Add the nullable batch identifier and the given missing indexes. Plain CREATE INDEX keeps the DDL transactional.
     *
     * @param  array<string, list<string>>  $indexes
     */
    public function addColumnsAndIndexes(Connection $connection, string $table, bool $addBatchUuid, array $indexes): void
    {
        if (! $addBatchUuid && $indexes === []) {
            return;
        }
        $connection->getSchemaBuilder()->table($table, static function (Blueprint $blueprint) use ($addBatchUuid, $indexes, $table): void {
            if ($addBatchUuid) {
                $blueprint->uuid('batch_uuid')->nullable();
            }
            foreach ($indexes as $name => $columns) {
                if ($name === self::newestIndex($table)) {
                    $blueprint->rawIndex('created_at DESC NULLS LAST, id DESC', $name);
                } else {
                    $blueprint->index($columns, $name);
                }
            }
        });
    }

    /**
     * @param  array<string, array{name:string,type:string,type_name:string,nullable:bool,default:mixed,auto_increment:bool,generation:mixed}>  $columns
     * @param  list<array{name:string,columns:list<string>,type:string,unique:bool,primary:bool}>  $indexes
     */
    private function assertLegacyShape(array $columns, array $indexes, string $driver): void
    {
        $missing = array_diff(self::REQUIRED_COLUMNS, array_keys($columns));
        if ($missing !== []) {
            throw new LogicException('Incompatible Activity schema; missing columns: '.implode(', ', $missing).'.');
        }
        if (isset($columns['owner_scope_key']) || isset($columns['owner_reference'])) {
            throw new LogicException('Existing owned Activity storage requires its Ownership adoption workflow.');
        }
        foreach (array_intersect_key($columns, array_flip(['id', 'subject_id', 'causer_id', 'batch_uuid'])) as $column) {
            if (! in_array($column['type_name'], ['char', 'string', 'text', 'uuid', 'varchar'], true) || $column['auto_increment']) {
                throw new LogicException('Activity adoption requires non-incrementing string identifiers.');
            }
        }
        if (! collect($indexes)->contains(static fn (array $index): bool => $index['primary'] && $index['columns'] === ['id'])) {
            throw new LogicException('Activity adoption requires a primary key on id.');
        }
        $jsonTypes = $driver === 'pgsql' ? ['json', 'jsonb'] : ['json', 'jsonb', 'longtext', 'text'];
        foreach (['properties', 'attribute_changes'] as $name) {
            if (! in_array($columns[$name]['type_name'], $jsonTypes, true)) {
                throw new LogicException("Activity adoption requires JSON-compatible [{$name}] storage.");
            }
        }
        if ($driver === 'pgsql') {
            foreach (self::TIMESTAMPS as $name) {
                if (preg_match('/^timestamp(?:\([0-6]\))? without time zone$/D', $columns[$name]['type']) !== 1) {
                    throw new LogicException("Activity adoption requires wall-clock timestamp storage for [{$name}].");
                }
            }
        }
        if (isset($columns['batch_uuid']) && ! $columns['batch_uuid']['nullable']) {
            throw new LogicException('The existing Activity batch_uuid column must be nullable.');
        }
        foreach (array_diff_key($columns, array_flip([...self::REQUIRED_COLUMNS, 'batch_uuid'])) as $column) {
            if (! $column['nullable'] && $column['default'] === null && $column['generation'] === null) {
                throw new LogicException("Activity adoption found an unmanaged required column [{$column['name']}].");
            }
        }
    }

    /**
     * @param  list<array{name:string,columns:list<string>,type:string,unique:bool,primary:bool}>  $indexes
     * @return array{array<string,list<string>>,list<string>}
     */
    private function planIndexes(Connection $connection, string $table, array $indexes): array
    {
        $required = $this->requiredIndexes($connection, $table);
        $newest = self::newestIndex($table);
        $definitions = [];
        if ($connection->getDriverName() === 'pgsql') {
            $definitions = array_column($connection->select(<<<'SQL'
                SELECT c.relname AS name, pg_catalog.pg_get_indexdef(i.indexrelid) AS definition
                FROM pg_catalog.pg_index i
                JOIN pg_catalog.pg_class c ON c.oid = i.indexrelid
                JOIN pg_catalog.pg_am a ON a.oid = c.relam
                WHERE i.indrelid = pg_catalog.to_regclass(?)
                  AND i.indisvalid AND i.indpred IS NULL AND a.amname = 'btree'
                SQL, [$connection->getQueryGrammar()->wrapTable($table)], false), 'definition', 'name');
            $eligible = array_values(array_filter($indexes, static fn (array $index): bool => isset($definitions[$index['name']])));
        } else {
            [$schema, $unqualified] = str_contains($table, '.') ? explode('.', $table, 2) : ['main', $table];
            $complete = array_column($connection->select(
                'SELECT name FROM pragma_index_list(?, ?) WHERE partial = 0',
                [$connection->getTablePrefix().$unqualified, $schema],
                false,
            ), 'name');
            $eligible = array_values(array_filter($indexes, static fn (array $index): bool => in_array($index['name'], $complete, true)));
        }
        $byName = array_column($indexes, null, 'name');
        $eligibleByName = array_column($eligible, null, 'name');
        $missing = [];
        $conflicts = [];
        foreach ($required as $name => $columns) {
            if (isset($byName[$name]) && (! isset($eligibleByName[$name]) || $byName[$name]['columns'] !== $columns)) {
                $conflicts[] = "index {$name} has incompatible columns";
            } elseif (isset($byName[$name]) && $name === $newest && (! is_string($definitions[$name] ?? null)
                || ! str_ends_with($definitions[$name], '(created_at DESC NULLS LAST, id DESC)'))) {
                $conflicts[] = "index {$name} has incompatible order";
            } elseif (! isset($byName[$name]) && ($name === $newest || ! collect($eligible)->contains(
                static fn (array $index): bool => array_slice($index['columns'], 0, count($columns)) === $columns,
            ))) {
                $missing[$name] = $columns;
            }
        }

        return [$missing, $conflicts];
    }

    private static function newestIndex(string $table): string
    {
        return str_replace('.', '_', $table).'_newest_first_index';
    }
}
