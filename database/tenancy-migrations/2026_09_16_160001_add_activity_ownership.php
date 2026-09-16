<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantAdoptionScope;

/** Adds immutable audit ownership only inside the selected adoption workflow. */
return new class extends Migration
{
    /** Prepare the ownership columns without changing the normal migration default. */
    public function up(): void
    {
        if (! Container::getInstance()->make(TenantAdoptionScope::class)->active()) {
            throw new TenantBoundaryViolation('Activity ownership schema requires coordinated adoption.');
        }
        $model = new ActivityLog;
        $connection = $model->getConnection();
        if ($connection->table($model->getTable())->exists()) {
            throw new TenantBoundaryViolation('Historical Activity ownership cannot be inferred.');
        }
        $schema = $connection->getSchemaBuilder();
        if (! $schema->hasColumn($model->getTable(), 'tenant_id')) {
            $schema->table($model->getTable(), static function (Blueprint $table): void {
                $table->uuid('tenant_id')->nullable();
            });
        }
        if (! $schema->hasColumn($model->getTable(), 'ownership_key')) {
            $schema->table($model->getTable(), static function (Blueprint $table): void {
                $table->string('ownership_key', 43);
            });
        }
        if (! $schema->hasIndex($model->getTable(), 'activity_ownership_created_idx')) {
            $schema->table($model->getTable(), static function (Blueprint $table): void {
                $table->index(['ownership_key', 'created_at', 'id'], 'activity_ownership_created_idx');
            });
        }
    }

    /** Preserve adopted ownership evidence; reversal requires a separate reviewed workflow. */
    public function down(): void {}
};
