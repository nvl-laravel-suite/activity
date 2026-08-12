<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Definitions\Tables\ActivityTables;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertCanonicalManagedStorage();

        if (Schema::hasTable(ActivityTables::ActivityLog)) {
            $defaultConnection = config('database.default');

            if (! is_string($defaultConnection) || trim($defaultConnection) === '') {
                throw new LogicException('The default database connection must be configured for Activity adoption.');
            }

            $this->assertAdoptableCanonicalTable(Schema::connection($defaultConnection));

            return;
        }

        Schema::create(ActivityTables::ActivityLog, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('event')->nullable();
            $table->string('causer_type')->nullable();
            $table->string('causer_id')->nullable();
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['causer_type', 'causer_id']);
            $table->index(['created_at', 'id']);
            $table->index(['event', 'created_at']);
        });
    }

    /**
     * Preserve created or adopted audit evidence during rollback.
     */
    public function down(): void {}

    /**
     * Ensure the vendor migration owns one immutable canonical target.
     */
    private function assertCanonicalManagedStorage(): void
    {
        $connection = config('activity.storage.connection');
        $table = config('activity.storage.table', ActivityTables::ActivityLog);
        $usesDefaultConnection = $connection === null;
        $usesCanonicalTable = is_string($table) && trim($table) === ActivityTables::ActivityLog;

        if (! $usesDefaultConnection || ! $usesCanonicalTable) {
            throw new LogicException(
                'The package-managed Activity migration only owns [activity_log] on the default connection; '.
                'disable activity.migrations.enabled and use an application-owned migration for custom storage.',
            );
        }
    }

    /**
     * Certify an existing canonical table before Laravel baselines this migration.
     */
    private function assertAdoptableCanonicalTable(Builder $schema): void
    {
        $requiredColumns = [
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
        $missingColumns = array_values(array_filter(
            $requiredColumns,
            static fn (string $column): bool => ! $schema->hasColumn(ActivityTables::ActivityLog, $column),
        ));

        if ($missingColumns !== []) {
            throw new LogicException(sprintf(
                'Existing Activity table [%s] cannot be baselined; missing columns: %s.',
                ActivityTables::ActivityLog,
                implode(', ', $missingColumns),
            ));
        }

        $columns = collect($schema->getColumns(ActivityTables::ActivityLog))->keyBy('name');
        $id = $columns->get('id');
        $subjectId = $columns->get('subject_id');
        $causerId = $columns->get('causer_id');
        $batchUuid = $columns->get('batch_uuid');
        $indexes = $schema->getIndexes(ActivityTables::ActivityLog);
        $hasPrimaryId = collect($indexes)->contains(
            static fn (array $index): bool => $index['primary'] === true
                && $index['columns'] === ['id'],
        );

        if (! is_array($id)
            || ! is_array($subjectId)
            || ! is_array($causerId)
            || ! is_array($batchUuid)
            || ! $this->isStringColumn($id)
            || ! $this->isStringColumn($subjectId)
            || ! $this->isStringColumn($causerId)
            || ! $this->isStringColumn($batchUuid)
            || $id['auto_increment'] !== false
            || ! $hasPrimaryId) {
            throw new LogicException(
                'Existing Activity table [activity_log] cannot be baselined; it requires a non-incrementing string primary ID plus string batch and morph identifiers.',
            );
        }

        $jsonColumns = ['properties'];
        if ($schema->hasColumn(ActivityTables::ActivityLog, 'attribute_changes')) {
            $jsonColumns[] = 'attribute_changes';
        }

        $compatibleJsonTypes = $schema->getConnection()->getDriverName() === 'pgsql'
            ? ['json', 'jsonb']
            : ['json', 'jsonb', 'longtext', 'text'];
        $invalidJsonColumns = array_values(array_filter(
            $jsonColumns,
            static fn (string $column): bool => ! in_array(
                strtolower($schema->getColumnType(ActivityTables::ActivityLog, $column)),
                $compatibleJsonTypes,
                true,
            ),
        ));

        if ($invalidJsonColumns !== []) {
            throw new LogicException(sprintf(
                'Existing Activity table [%s] cannot be baselined; non-JSON-compatible columns: %s.',
                ActivityTables::ActivityLog,
                implode(', ', $invalidJsonColumns),
            ));
        }

        $requiredIndexes = [
            ['log_name'],
            ['subject_type', 'subject_id'],
            ['causer_type', 'causer_id'],
            ['created_at', 'id'],
            ['event', 'created_at'],
        ];
        $missingIndexes = array_values(array_filter(
            $requiredIndexes,
            fn (array $indexColumns): bool => ! $this->hasIndexColumns($indexes, $indexColumns),
        ));

        if ($missingIndexes !== []) {
            throw new LogicException(sprintf(
                'Existing Activity table [%s] cannot be baselined; missing indexes: %s.',
                ActivityTables::ActivityLog,
                implode(', ', array_map(
                    static fn (array $indexColumns): string => '('.implode(', ', $indexColumns).')',
                    $missingIndexes,
                )),
            ));
        }
    }

    /**
     * Determine whether a schema column provides string identifier storage.
     *
     * @param  array<string, mixed>  $column
     */
    private function isStringColumn(array $column): bool
    {
        $typeName = $column['type_name'] ?? null;

        return is_string($typeName)
            && in_array(strtolower($typeName), ['char', 'string', 'text', 'uuid', 'varchar'], true);
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
};
