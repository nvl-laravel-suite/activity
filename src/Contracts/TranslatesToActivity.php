<?php

declare(strict_types=1);

namespace Nvl\Activity\Contracts;

use Nvl\Activity\Data\Display\ActivityItem;

/**
 * Translates a source-specific DTO into the canonical activity timeline item.
 */
interface TranslatesToActivity
{
    /**
     * Convert the source item into the shared timeline DTO.
     */
    public function toActivityItem(): ActivityItem;
}
