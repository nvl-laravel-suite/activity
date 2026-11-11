<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/** Rebuilds the schema per test, without a wrapping transaction, for tests that change schema and then roll back. */
abstract class CommittedTestCase extends TestCase
{
    public function refreshDatabase(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->app[Kernel::class]->setArtisan(null);
        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }
}
