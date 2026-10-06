<?php

declare(strict_types=1);

namespace Nvl\Activity\Events;

/** @api
 * @deprecated Use ActivityLogPurgeQueued with the versioned scalar payload; removed no earlier than major 6. */
class_alias(ActivityLogPurgeQueued::class, __NAMESPACE__.'\\ActivityLogPurgeQueuedEvent');
