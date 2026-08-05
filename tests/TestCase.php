<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Activitylog\ActivitylogServiceProvider;

/**
 * Boots Activity and its runtime dependencies in an isolated Laravel application.
 */
abstract class TestCase extends Orchestra
{
    use DatabaseMigrations;

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            DataServiceProvider::class,
            SupportServiceProvider::class,
            ActivitylogServiceProvider::class,
            ActivityServiceProvider::class,
        ];
    }
}
