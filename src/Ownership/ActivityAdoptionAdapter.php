<?php

declare(strict_types=1);

namespace Nvl\Activity\Ownership;

use Nvl\Activity\Services\ActivityModelRegistry;
use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Ownership\Services\OwnershipAdoptionStore;
use Nvl\Ownership\Services\OwnershipSchema;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipBackfillResult;
use Nvl\Ownership\ValueObjects\OwnershipVerification;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;

/**
 * Adopts exact reviewed Activity facts independently from their optional subjects.
 *
 * @internal
 */
final readonly class ActivityAdoptionAdapter implements OwnershipAdoptionAdapter
{
    /** Resolve maintenance-only mapping and schema capabilities. */
    public function __construct(private OwnershipAdoptionStore $mappings, private OwnershipSchema $schema) {}

    /** @return list<string> */
    public function resources(): array
    {
        return ['activity.events'];
    }

    /** Replace only the known global natural identity during the fenced expansion. */
    public function prepare(OwnershipAdoptionPlan $plan): void
    {
        $this->schema->prepare('activity.events');

    }

    /** Assign every actual row from an immutable reviewed mapping, never a default. */
    public function backfill(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult
    {
        $model = ActivityModelRegistry::newInstance();
        $connection = $model->getConnection();
        $rows = $connection->table($model->getTable())->when($cursor !== null, static fn ($query) => $query->where('id', '>', $cursor))->orderBy('id')->limit($limit)->get();
        $last = null;
        $connection->transaction(function () use ($rows, $plan, $model, $connection, &$last): void {
            foreach ($rows as $row) {
                if (! is_string($row->id)) {
                    throw new OwnershipConfigurationInvalid('Activity adoption requires the canonical UUID.');
                }
                $mapping = $this->mappings->forRecord($plan, 'activity.events', $row->id);
                if (isset($row->tenant_id) && $mapping->legacyTenantId !== $row->tenant_id) {
                    throw new OwnershipConfigurationInvalid('The reviewed mapping must name the original tenant.');
                }
                $connection->table($model->getTable())->where('id', $row->id)->update([
                    'owner_scope_key' => $mapping->owner?->scopeKey() ?? 'central',
                    'owner_reference' => $mapping->owner === null ? null : json_encode($mapping->owner->toArray(), JSON_THROW_ON_ERROR),
                ]);
                $last = $row->id;
            }
        });

        return new OwnershipBackfillResult($rows->count() === $limit ? $last : null, $rows->count());
    }

    /** Check canonical rows before final source activation. */
    public function verify(OwnershipAdoptionPlan $plan): OwnershipVerification
    {
        $this->schema->verify('activity.events');

        return new OwnershipVerification([]);
    }

    /** Install immutable partition constraints before publishing readiness. */
    public function activate(OwnershipAdoptionPlan $plan): void
    {
        $this->schema->constrain('activity.events');
    }
}
