<?php

declare(strict_types=1);

namespace Nvl\Activity\Tenancy;

use Illuminate\Database\Migrations\Migrator;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantAdoptionSupport;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;

/** Adopts empty audit storage without inventing ownership for historical evidence. */
final readonly class ActivityAdoptionAdapter implements TenantAdoptionAdapter
{
    /** Use Laravel's migration repository for the separately selected ownership schema. */
    public function __construct(private Migrator $migrator, private TenantAdoptionSupport $adoption) {}

    /** @return list<string> */
    public function resources(): array
    {
        return ['activity.events'];
    }

    /** Prepare only empty canonical storage; legacy assignment is a later workflow. */
    public function prepare(TenantAdoptionPlan $plan): void
    {
        $this->assertEmpty($plan);
        $this->migrator->usingConnection($plan->connection, fn () => $this->migrator->run([__DIR__.'/../../database/tenancy-migrations'], ['force' => true]));
    }

    /** Refuse historical ownership inference throughout the maintenance window. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        $this->assertEmpty($plan);

        return new TenantBackfillResult(null, 0);
    }

    /**
     * Certify the actual schema and empty dataset without relying on migration markers.
     *
     * @phpstan-impure
     */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $connection = $this->adoption->connection($plan, 'activity.events');
        $table = (new ActivityLog)->getTable();
        $schema = $connection->getSchemaBuilder();
        $columns = collect($schema->getColumns($table))->keyBy('name');
        $ownership = $columns->get('ownership_key');
        $valid = $columns->has('tenant_id') && is_array($ownership) && $ownership['nullable'] === false
            && $schema->hasIndex($table, 'activity_ownership_created_idx')
            && ! $connection->table($table)->exists();

        return new TenantVerification($valid ? [] : ['activity_ownership_schema_or_legacy_rows']);
    }

    /** Recheck final constraints before the coordinator publishes active markers. */
    public function activate(TenantAdoptionPlan $plan): void
    {
        if (! $this->verify($plan)->passed()) {
            throw new TenantBoundaryViolation('Activity ownership adoption did not verify.');
        }
    }

    /** Deny unsupported existing-data adoption before any ownership schema mutation. */
    private function assertEmpty(TenantAdoptionPlan $plan): void
    {
        if ($this->adoption->connection($plan, 'activity.events')->table((new ActivityLog)->getTable())->exists()) {
            throw new TenantBoundaryViolation('Existing Activity evidence requires an explicit historical adoption workflow.');
        }
    }
}
