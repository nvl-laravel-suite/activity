<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LogicException;
use ReflectionClass;
use Spatie\Activitylog\ActivitylogServiceProvider;

/**
 * Boots Activity next to the real Spatie v5 engine, and skips when Spatie is not installed.
 */
abstract class SpatieIntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(ActivitylogServiceProvider::class)) {
            $this->markTestSkipped('spatie/laravel-activitylog is not installed; native capture runs without it.');
        }

        parent::setUp();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            ...(class_exists(ActivitylogServiceProvider::class) ? [ActivitylogServiceProvider::class] : []),
        ];
    }

    /** Create Spatie's own log table from its shipped migration, plus one business table per engine. */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        $package = dirname((string) (new ReflectionClass(ActivitylogServiceProvider::class))->getFileName(), 2);
        $migration = require $package.'/database/migrations/create_activity_log_table.php.stub';
        if (! is_object($migration) || ! method_exists($migration, 'up')) {
            throw new LogicException('Spatie no longer ships its activity_log migration stub.');
        }
        $migration->up();

        foreach (['spatie_bridge_subjects', 'native_bridge_subjects'] as $table) {
            Schema::create($table, function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->string('secret')->nullable();
                $table->string('other')->nullable();
                $table->unsignedInteger('priority')->nullable();
                $table->softDeletes();
            });
        }
    }
}
