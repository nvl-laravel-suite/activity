<?php

declare(strict_types=1);

namespace Nvl\Activity\Builders;

use Illuminate\Database\Query\Builder;
use LogicException;
use Nvl\Activity\Services\ActivityRetentionContext;

/**
 * Enforces native append-only writes even for quiet model and bulk Eloquent operations.
 *
 * @internal
 */
final class ActivityQuery extends Builder
{
    public function update(array $values): int
    {
        throw new LogicException('Activity facts are immutable.');
    }

    /** @param array<string,mixed> $values */
    public function updateFrom(array $values): int
    {
        throw new LogicException('Activity facts are immutable.');
    }

    /** @param array<array-key,mixed> $values
     * @param  string|list<string>  $uniqueBy
     * @param  list<string>|null  $update
     */
    public function upsert(array $values, array|string $uniqueBy, ?array $update = null): int
    {
        throw new LogicException('Activity facts cannot be upserted.');
    }

    public function delete($id = null): int
    {
        app(ActivityRetentionContext::class)->assertActive();

        return parent::delete($id);
    }

    public function truncate(): void
    {
        throw new LogicException('Activity facts cannot be truncated.');
    }
}
