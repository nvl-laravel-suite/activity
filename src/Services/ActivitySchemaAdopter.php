<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use LogicException;
use Nvl\Activity\Ownership\ActivityOwnershipGuard;
use Nvl\Support\Enums\SystemActor;

/**
 * Reviews and completes an existing unowned Spatie v5 audit table in place.
 *
 * @api
 *
 * @phpstan-type Plan array{table:string,fingerprint:string,rows:int,add_batch_uuid:bool,widen_timestamps:list<string>,missing_indexes:array<string,list<string>>,conflicts:list<string>,ready:bool}
 */
final readonly class ActivitySchemaAdopter
{
    private ActivitySchemaInspector $schema;

    public function __construct(ActivityAccess $access, ActivityOwnershipGuard $ownership)
    {
        $this->schema = new ActivitySchemaInspector($access, $ownership);
    }

    /** @return Plan Read-only review of the configured Activity table and its historical rows. */
    public function plan(Authenticatable|SystemActor $actor): array
    {
        [$connection, $table] = $this->source($actor);

        return $this->schema->review($connection, $table);
    }

    /** @return Plan Apply additive schema work and reconcile before committing. */
    public function apply(Authenticatable|SystemActor $actor, string $fingerprint): array
    {
        [$connection, $table] = $this->source($actor);
        $this->assertOutsideTransaction($connection);

        return $connection->transaction(function () use ($connection, $table, $fingerprint): array {
            $this->schema->lock($connection, $table);
            $plan = $this->reviewed($connection, $table, $fingerprint);
            if ($plan['ready']) {
                return $plan;
            }
            $this->schema->widenTimestamps($connection, $table, $plan['widen_timestamps']);
            $this->schema->addColumnsAndIndexes($connection, $table, $plan['add_batch_uuid'], $plan['missing_indexes']);

            return $this->ready($connection, $table, $fingerprint);
        });
    }

    /** @return Plan Reconcile the committed table before enabling native readers and writers. */
    public function verify(Authenticatable|SystemActor $actor, string $fingerprint): array
    {
        [$connection, $table] = $this->source($actor);
        $this->assertOutsideTransaction($connection);

        return $this->ready($connection, $table, $fingerprint);
    }

    /** @return array{Connection,string} */
    private function source(Authenticatable|SystemActor $actor): array
    {
        $this->schema->authorize($actor);

        return $this->schema->target();
    }

    private function assertOutsideTransaction(Connection $connection): void
    {
        if ($connection->transactionLevel() !== 0) {
            throw new LogicException('Activity adoption requires a committed source outside an existing transaction.');
        }
    }

    /** @return Plan */
    private function reviewed(Connection $connection, string $table, string $fingerprint): array
    {
        $plan = $this->schema->review($connection, $table);
        if ($plan['conflicts'] !== []) {
            throw new LogicException('Activity adoption has unresolved conflicts: '.implode(', ', $plan['conflicts']).'.');
        }
        if (! hash_equals($plan['fingerprint'], $fingerprint)) {
            throw new LogicException('Activity adoption fingerprint changed; review a new plan.');
        }

        return $plan;
    }

    /** @return Plan */
    private function ready(Connection $connection, string $table, string $fingerprint): array
    {
        $plan = $this->reviewed($connection, $table, $fingerprint);
        if (! $plan['ready']) {
            throw new LogicException('Activity adoption is incomplete; apply the reviewed plan before activation.');
        }

        return $plan;
    }
}
