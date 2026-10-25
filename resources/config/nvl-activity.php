<?php

declare(strict_types=1);

use Nvl\Activity\Definitions\Tables\ActivityTables;

return ['queue' => ['connection' => null, 'name' => null], 'adoption' => ['spatie' => false], 'migrations' => ['enabled' => true], 'storage' => ['connection' => null, 'table' => ActivityTables::ActivityLog], 'retention' => ['schedule' => ['enabled' => false]]];
