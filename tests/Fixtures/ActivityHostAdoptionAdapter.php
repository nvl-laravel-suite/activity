<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;

/** Owns the fixture host's actual empty tenant schema through the real coordinator. */
final class ActivityHostAdoptionAdapter implements TenantAdoptionAdapter
{
    /** @return list<string> */
    public function resources(): array
    {
        return ['activity-fixtures.subjects'];
    }

    /** Prepare the host's canonical ownership schema. */
    public function prepare(TenantAdoptionPlan $plan): void
    {
        if (! Schema::hasTable('tenant_activity_subjects')) {
            Schema::create('tenant_activity_subjects', static function (Blueprint $table): void {
                $table->id();
                $table->uuid('tenant_id');
                $table->string('name');
                $table->timestamps();
            });
        }
    }

    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        return new TenantBackfillResult(null, 0);
    }

    /** Verify the host schema rather than fabricating adoption markers. */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        return new TenantVerification(Schema::hasColumns('tenant_activity_subjects', ['id', 'tenant_id', 'name']) ? [] : ['host_schema_missing']);
    }

    /** The newly created host ownership column is already non-null. */
    public function activate(TenantAdoptionPlan $plan): void {}
}
