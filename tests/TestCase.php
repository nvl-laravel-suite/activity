<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Activity\Contracts\ActivityAuthorization;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Activity\Tests\Fixtures\AllowsActivity;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Activitylog\ActivitylogServiceProvider;

/**
 * Boots Activity and its runtime dependencies in an isolated Laravel application.
 */
abstract class TestCase extends Orchestra
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(ActivityAuthorization::class, AllowsActivity::class);
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LocaleServiceProvider::class,
            SupportServiceProvider::class,
            ActivitylogServiceProvider::class,
            ActivityServiceProvider::class,
        ];
    }
}
