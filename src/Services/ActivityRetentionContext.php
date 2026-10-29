<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use LogicException;

/**
 * Internal scoped exception to append-only deletion for admitted native retention work.
 *
 * @internal
 */
final class ActivityRetentionContext
{
    private bool $active = false;

    /**
     * @template T
     *
     * @param  callable():T  $operation
     * @return T
     */
    public function run(callable $operation): mixed
    {
        $previous = $this->active;
        $this->active = true;
        try {
            return $operation();
        } finally {
            $this->active = $previous;
        }
    }

    public function assertActive(): void
    {
        if (! $this->active) {
            throw new LogicException('Activity facts can only be deleted by native retention.');
        }
    }
}
