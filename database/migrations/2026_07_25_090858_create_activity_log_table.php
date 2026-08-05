<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string TABLE_NAME = 'activity_log';

    public function up(): void
    {
        $this->assertCanonicalManagedStorage();

        Schema::create(self::TABLE_NAME, function (Blueprint $table): void {
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

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE_NAME);
    }

    /**
     * Ensure the vendor migration owns one immutable and safely reversible target.
     */
    private function assertCanonicalManagedStorage(): void
    {
        $connection = config('activity.storage.connection');
        $table = config('activity.storage.table', self::TABLE_NAME);
        $usesDefaultConnection = $connection === null;
        $usesCanonicalTable = is_string($table) && trim($table) === self::TABLE_NAME;

        if (! $usesDefaultConnection || ! $usesCanonicalTable) {
            throw new LogicException(
                'The package-managed Activity migration only owns [activity_log] on the default connection; '.
                'disable activity.migrations.enabled and use an application-owned migration for custom storage.',
            );
        }
    }
};
