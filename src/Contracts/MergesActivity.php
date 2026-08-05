<?php

declare(strict_types=1);

namespace Nvl\Activity\Contracts;

use Nvl\Activity\Data\Display\ActivityItem;

/**
 * Exposes a unified host-owned activity timeline for an entity.
 */
interface MergesActivity
{
    /**
     * Build the complete timeline payload for the host model.
     *
     * @param  int|null  $limit  Optional maximum number of newest timeline rows to return
     * @return array<int, ActivityItem>
     */
    public function buildActivityTimeline(?int $limit = null): array;
}
