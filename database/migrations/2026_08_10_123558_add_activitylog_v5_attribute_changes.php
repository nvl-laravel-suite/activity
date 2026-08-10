<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string TABLE_NAME = 'activity_log';

    /**
     * Make a compatible Activitylog v4 schema ready for required v5 writes.
     */
    public function up(): void
    {
        $this->assertCanonicalManagedStorage();

        if (! Schema::hasTable(self::TABLE_NAME)) {
            throw new LogicException(
                'The Activitylog v5 bridge requires the canonical [activity_log] table.',
            );
        }

        if (Schema::hasColumn(self::TABLE_NAME, 'attribute_changes')) {
            return;
        }

        Schema::table(self::TABLE_NAME, function (Blueprint $table): void {
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
        $table = config('activity.storage.table', self::TABLE_NAME);

        if ($connection !== null || ! is_string($table) || trim($table) !== self::TABLE_NAME) {
            throw new LogicException(
                'The package-managed Activity migration only owns [activity_log] on the default connection; '.
                'disable activity.migrations.enabled and use an application-owned migration for custom storage.',
            );
        }
    }
};
