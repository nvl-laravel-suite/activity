<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Definitions\Tables\ActivityTables;
use Nvl\Support\Config\PackageStorage;

/**
 * PostgreSQL sorts NULL first in descending order, so newest-first reads need an index
 * declared with the same `NULLS LAST` ordering. Other drivers already use `(created_at, id)`.
 */
return new class extends Migration
{
    /** Use the effective package connection for Laravel's migration transaction. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('activity');
    }

    public function up(): void
    {
        $schema = $this->schema();
        $table = ActivityTables::get(ActivityTables::ActivityLog);

        if (! $this->applies($schema, $table) || $schema->hasIndex($table, $this->indexName($table))) {
            return;
        }

        $schema->table($table, function (Blueprint $blueprint) use ($table): void {
            $blueprint->rawIndex('created_at DESC NULLS LAST, id DESC', $this->indexName($table));
        });
    }

    public function down(): void
    {
        $schema = $this->schema();
        $table = ActivityTables::get(ActivityTables::ActivityLog);

        if (! $this->applies($schema, $table) || ! $schema->hasIndex($table, $this->indexName($table))) {
            return;
        }

        $schema->table($table, function (Blueprint $blueprint) use ($table): void {
            $blueprint->dropIndex($this->indexName($table));
        });
    }

    private function schema(): Builder
    {
        return Schema::connection(PackageStorage::connection('activity'));
    }

    private function applies(Builder $schema, string $table): bool
    {
        return $schema->getConnection()->getDriverName() === 'pgsql' && $schema->hasTable($table);
    }

    private function indexName(string $table): string
    {
        return str_replace('.', '_', $table).'_newest_first_index';
    }
};
