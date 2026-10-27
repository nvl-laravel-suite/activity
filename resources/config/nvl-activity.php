<?php

declare(strict_types=1);

use Nvl\Activity\Definitions\Tables\ActivityTables;
use Nvl\Activity\Models\ActivityLog;

return [
    'models' => ['activity_log' => ActivityLog::class], 'queue' => ['connection' => null, 'name' => null], 'adoption' => ['spatie' => false], 'migrations' => ['enabled' => true], 'storage' => ['connection' => null, 'table' => ActivityTables::ActivityLog], 'retention' => ['schedule' => ['enabled' => false]]];
