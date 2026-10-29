<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Activity\Contracts\ActivityAuthorization;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Activity\Tests\Fixtures\AllowsActivity;
use Nvl\Activity\Tests\Fixtures\ConfiguresActivityPolicies;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots Activity and its runtime dependencies in an isolated Laravel application.
 */
abstract class TestCase extends Orchestra
{
    use ConfiguresActivityPolicies;
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureActivityPolicies();
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
            ActivityServiceProvider::class,
        ];
    }
}
