<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Definitions\Tables\ActivityTables;
use Nvl\Support\Config\PackageStorage;

return new class extends Migration
{
    /** Use the effective package connection for Laravel's migration transaction. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('activity');
    }

    /**
     * Make a compatible Activitylog v4 schema ready for required v5 writes.
     */
    public function up(): void
    {
        $this->assertCanonicalManagedStorage();

        if (! Schema::connection(PackageStorage::connection('activity'))->hasTable(ActivityTables::get(ActivityTables::ActivityLog))) {
            throw new LogicException(
                'The Activitylog v5 bridge requires the canonical [activity_log] table.',
            );
        }

        if (Schema::connection(PackageStorage::connection('activity'))->hasColumn(ActivityTables::get(ActivityTables::ActivityLog), 'attribute_changes')) {
            return;
        }

        Schema::connection(PackageStorage::connection('activity'))->table(ActivityTables::get(ActivityTables::ActivityLog), function (Blueprint $table): void {
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
        PackageStorage::connection('activity');
    }
};
