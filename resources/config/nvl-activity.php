<?php

declare(strict_types=1);
use Nvl\Activity\Definitions\Tables\ActivityTables;

return ['queue' => ['connection' => null, 'name' => null], 'adoption' => ['spatie' => false], 'tenancy' => ['active_tenant_worklist' => []], 'routes' => ['enabled' => false, 'prefix' => 'nvl/api/v1', 'middleware' => ['api'], 'management_middleware' => ['auth', 'throttle:60,1'], 'timeline_subjects' => []], 'authorization' => ['abilities' => ['view' => null, 'timeline' => null, 'purge' => null]], 'migrations' => ['enabled' => true], 'storage' => ['connection' => null, 'table' => ActivityTables::ActivityLog], 'retention' => ['schedule' => ['enabled' => false]]];
