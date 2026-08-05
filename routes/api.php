<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nvl\Activity\Http\Controllers\Api\ActivityCauserSuggestionsApiController;
use Nvl\Activity\Http\Controllers\Api\ActivityLogsApiController;
use Nvl\Activity\Http\Middleware\ForceActivityJsonResponse;

$middleware = array_values(array_filter(array_map(
    static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value,
    (array) config('activity.routes.management_middleware', ['auth']),
), static fn (mixed $value): bool => is_string($value) && $value !== ''));

Route::middleware([
    ForceActivityJsonResponse::class,
    ...$middleware,
])
    ->group(function (): void {
        Route::prefix('activities')->name('activities.')->group(function (): void {
            Route::get('/', [ActivityLogsApiController::class, 'index'])->name('index');
            Route::get('timeline', [ActivityLogsApiController::class, 'timeline'])->name('timeline');
            Route::get('causers/suggestions', ActivityCauserSuggestionsApiController::class)
                ->name('causers.suggestions');
            Route::post('purge', [ActivityLogsApiController::class, 'purge'])->name('purge');
            Route::post('purge-system', [ActivityLogsApiController::class, 'purgeSystem'])->name('purge-system');
        });
    });
