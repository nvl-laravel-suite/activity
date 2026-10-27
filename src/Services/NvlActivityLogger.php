<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\Activitylog\Support\ActivityLogger;

/**
 * Writes NVL records independently of the host's global Spatie model.
 *
 * @internal
 */
final class NvlActivityLogger extends ActivityLogger
{
    /** Initialize the package-owned activity model using native logger defaults. */
    protected function getActivity(): ActivityContract
    {
        if (! $this->activity instanceof ActivityContract) {
            $this->activity = ActivityModelRegistry::newInstance();
            $this->useLog($this->defaultLogName)
                ->withChanges([])
                ->withProperties([])
                ->causedByAnonymous();
        }

        return $this->activity;
    }
}
