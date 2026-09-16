<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Activity\Tests\Fixtures\TenantScenario;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;
use Spatie\Activitylog\ActivitylogServiceProvider;

/** Boots Activity with a real adopted tenant schema and no ambient transaction. */
abstract class TenancyTestCase extends Orchestra
{
    use DatabaseMigrations;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [DataServiceProvider::class, SupportServiceProvider::class,
            TenancyServiceProvider::class, ActivitylogServiceProvider::class,
            ActivityServiceProvider::class];
    }

    /** Configure tenant ownership before package providers finish booting. */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('tenancy.enabled', true);
        $app['config']->set('tenancy.profile', 'application');
        $app['config']->set('tenancy.resources', ['activity' => 'tenant']);
        TenantScenario::bind($app);
    }

    /** Load Foundation's opt-in schema after Testbench refreshes package migrations. */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        $provider = new ReflectionClass(TenancyServiceProvider::class);
        $this->loadMigrationsFrom(dirname($provider->getFileName()).'/../../database/migrations/tenancy');
    }

    /** Adopt the selected empty Activity store through the real coordinator. */
    protected function setUp(): void
    {
        parent::setUp();
        TenantScenario::activate(['activity']);
    }
}
