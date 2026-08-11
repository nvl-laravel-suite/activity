<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Definitions\Tables\ActivityTables;

return new class extends Migration
{
    /**
     * Make a compatible Activitylog v4 schema ready for required v5 writes.
     */
    public function up(): void
    {
        $this->assertCanonicalManagedStorage();

        if (! Schema::hasTable(ActivityTables::ActivityLog)) {
            throw new LogicException(
                'The Activitylog v5 bridge requires the canonical [activity_log] table.',
            );
        }

        if (Schema::hasColumn(ActivityTables::ActivityLog, 'attribute_changes')) {
            return;
        }

        Schema::table(ActivityTables::ActivityLog, function (Blueprint $table): void {
            $table->json('attribute_changes')->nullable();
        });
    }

    /**
     * Preserve v5 change evidence during rollback.
     */
    public function down(): void {}

    /**
     * Ensure the vendor bridge owns only canonical package-managed storage.
     */
    private function assertCanonicalManagedStorage(): void
    {
        $connection = config('activity.storage.connection');
        $table = config('activity.storage.table', ActivityTables::ActivityLog);

        if ($connection !== null || ! is_string($table) || trim($table) !== ActivityTables::ActivityLog) {
            throw new LogicException(
                'The package-managed Activity migration only owns [activity_log] on the default connection; '.
                'disable activity.migrations.enabled and use an application-owned migration for custom storage.',
            );
        }
    }
};
