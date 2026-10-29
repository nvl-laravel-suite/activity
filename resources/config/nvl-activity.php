<?php

declare(strict_types=1);

use Nvl\Activity\Definitions\Tables\ActivityTables;
use Nvl\Activity\Models\ActivityLog;

return [
    'models' => ['activity_log' => ActivityLog::class], 'queue' => ['connection' => null, 'name' => null], 'events' => [], 'reads' => ['include_soft_deleted_subjects' => false], 'migrations' => ['enabled' => true], 'retention' => ['schedule' => ['enabled' => false]], 'connection' => null, 'tables' => ['log' => ActivityTables::ActivityLog]];
