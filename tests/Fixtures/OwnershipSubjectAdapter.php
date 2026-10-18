<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Fixtures;

use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Ownership\Services\OwnershipSchema;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipBackfillResult;
use Nvl\Ownership\ValueObjects\OwnershipVerification;

/** Activates empty host-owned subject storage through actual partition constraints. */
final readonly class OwnershipSubjectAdapter implements OwnershipAdoptionAdapter
{
    public function __construct(private OwnershipSchema $schema) {}

    public function resources(): array
    {
        return ['fixture.subjects'];
    }

    public function prepare(OwnershipAdoptionPlan $plan): void
    {
        $this->schema->prepare('fixture.subjects');
    }

    public function backfill(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult
    {
        return new OwnershipBackfillResult(null, 0);
    }

    public function verify(OwnershipAdoptionPlan $plan): OwnershipVerification
    {
        $this->schema->verify('fixture.subjects');

        return new OwnershipVerification([]);
    }

    public function activate(OwnershipAdoptionPlan $plan): void
    {
        $this->schema->constrain('fixture.subjects');
    }
}
