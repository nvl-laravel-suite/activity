<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Activity\Contracts\ActivityAuthorization;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Activity\Tests\Fixtures\AllowsActivity;
use Nvl\Activity\Tests\Fixtures\OwnershipActor;
use Nvl\Activity\Tests\Fixtures\OwnershipCompany;
use Nvl\Activity\Tests\Fixtures\OwnershipSubject;
use Nvl\Activity\Tests\Fixtures\OwnershipSubjectAdapter;
use Nvl\Ownership\Contracts\CentralAccess;
use Nvl\Ownership\Contracts\OwnerAccess;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Providers\OwnershipServiceProvider;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Orchestra\Testbench\TestCase;
use Spatie\Activitylog\ActivitylogServiceProvider;

/** Exercises committed ownership and audit behavior without NVL Tenancy or Auth providers. */
abstract class OwnershipTestCase extends TestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [LocaleServiceProvider::class, SupportServiceProvider::class, OwnershipServiceProvider::class, ActivitylogServiceProvider::class, ActivityServiceProvider::class];
    }

    /** Configure owned package roots before immutable resource registration. */
    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'nvl-core.owners' => [OwnershipCompany::class, OwnershipActor::class],
            'nvl-ownership.enabled' => true, 'nvl-ownership.migrations.enabled' => false,
            'nvl-ownership.central_operations' => ['activity-maintain'],
            'nvl-tenancy.enabled' => false,
            'nvl-activity.causer_suggestions.model' => OwnershipActor::class,
            'auth.providers.users.model' => OwnershipActor::class,
        ]);
        if (getenv('NVL_OWNERSHIP_TEST_DRIVER') === 'pgsql') {
            $database = getenv('NVL_OWNERSHIP_TEST_DATABASE');
            if (! is_string($database) || preg_match('/^nvl_own_test_[a-z0-9_]+$/D', $database) !== 1) {
                throw new \RuntimeException('Native integration tests require a dedicated nvl_own_test_ database.');
            }
            $app['config']->set('database.connections.sqlite', ['driver' => 'pgsql', 'url' => null, 'host' => '/tmp', 'port' => '5432', 'database' => $database, 'username' => get_current_user(), 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer']);
        }
    }

    /** Install real package storage and activate through the reviewed coordinator. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(ActivityAuthorization::class, AllowsActivity::class);
        $schema = DB::getSchemaBuilder();
        if (getenv('NVL_OWNERSHIP_TEST_DRIVER') === 'pgsql') {
            if (DB::connection()->getDatabaseName() !== getenv('NVL_OWNERSHIP_TEST_DATABASE')) {
                throw new \RuntimeException('The actual native source differs from the dedicated test database.');
            }
            $schema->dropAllTables();
        }
        foreach (['activity_test_companies', 'activity_test_actors'] as $table) {
            $schema->create($table, static fn (Blueprint $table) => $table->unsignedBigInteger('id')->primary());
        }
        $schema->table('activity_test_actors', static function (Blueprint $table): void {
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
        });
        OwnershipCompany::query()->insert([['id' => 7], ['id' => 8]]);
        OwnershipActor::query()->create(['id' => 1, 'name' => 'Operator', 'email' => 'operator@example.test', 'password' => 'private hash']);
        $schema->create('activity_test_subjects', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->foreign('company_id')->references('id')->on('activity_test_companies');
        });
        $this->app->make(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('fixture.subjects', 'fixture', OwnershipSubject::class, OwnershipSubject::ownershipDefinition()));
        $this->app->make(OwnershipAdoptionRegistry::class)->register('fixture', OwnershipSubjectAdapter::class);
        foreach (glob(__DIR__.'/../database/migrations/*.php') ?: [] as $path) {
            (require $path)->up();
        }
        (require __DIR__.'/../../ownership/database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
        $this->app->bind(OwnerAccess::class, static fn () => new class implements OwnerAccess
        {
            public function assertCanEnter(Authenticatable $actor, Model $owner): void {}
        });
        $this->app->bind(CentralAccess::class, static fn () => new class implements CentralAccess
        {
            public function assertCanRun(string $operation, Authenticatable $actor): void {}
        });
        $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
        {
            public function authorize(OwnershipOperation $operation): void {}
        });
        $this->app->instance(MaintenanceMode::class, new class implements MaintenanceMode
        {
            public function activate(array $payload): void {}

            public function deactivate(): void {}

            public function active(): bool
            {
                return true;
            }

            public function data(): array
            {
                return [];
            }
        });
        $operation = new OwnershipOperation('adopt-activity', 'operator', '1');
        $coordinator = $this->app->make(OwnershipAdoptionCoordinator::class);
        $plan = $coordinator->prepare(['activity', 'fixture'], $this->reviewedMappings(), $operation);
        $coordinator->backfill($plan, 100, $operation);
        $coordinator->activate($plan, $operation);
    }

    /** @return list<OwnershipMapping> */
    protected function reviewedMappings(): array
    {
        return [];
    }
}
