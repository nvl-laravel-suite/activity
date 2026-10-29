<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Ownership;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityModelRegistry;
use Nvl\Activity\Services\ActivityReader;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Tests\Fixtures\CustomActivityLog;
use Nvl\Activity\Tests\Fixtures\OwnershipActor;
use Nvl\Activity\Tests\Fixtures\OwnershipCompany;
use Nvl\Activity\Tests\OwnershipTestCase;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

trait ConfiguresOwnedActivityModelEnvironment
{
    /** @var array<string, class-string<Model>>|null */
    protected ?array $previousMorphMap = null;

    protected function restoreConfiguredMorphMap(): void
    {
        Relation::morphMap($this->previousMorphMap ?? [], false);
    }

    protected function defineEnvironment($app): void
    {
        $this->previousMorphMap ??= Relation::morphMap();
        Relation::morphMap([], false);
        parent::defineEnvironment($app);
        $app['config']->set('nvl-activity.models.activity_log', CustomActivityLog::class);
    }
}

uses(OwnershipTestCase::class, ConfiguresOwnedActivityModelEnvironment::class);

afterEach(function (): void {
    $this->restoreConfiguredMorphMap();
});

it('selected root survives adoption canonical hydration and captured retention', function (): void {
    $this->assertSame(CustomActivityLog::class, app(OwnershipResourceRegistry::class)->get('activity.events')->model);
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function (): void {
        $log = app(ActivityRecorder::class)->record(['event' => 'old'], SystemActor::Background);
        $log->created_at = now()->subDays(120);
        $log->getConnection()->table($log->getTable())->where('id', $log->id)->update($log->getDirty());
        $this->assertSame(CustomActivityLog::class, app(ActivityReader::class)->paginate([], SystemActor::Background)->first()::class);
        $this->assertSame(app(OwnershipBoundary::class)->attributes('activity.events')['owner_scope_key'], $log->owner_scope_key);
        (new PurgeActivityLogsJob(90))->handle(app(OwnershipBoundary::class));
        $this->assertSame(0, ActivityModelRegistry::query()->count());
        $this->expectException(OwnershipViolation::class);
        ActivityLog::query()->count();
    });
});
