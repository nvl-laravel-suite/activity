<?php

declare(strict_types=1);

namespace Nvl\Activity\Providers;

use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;
use Nvl\Activity\Http\Middleware\ForceActivityJsonResponse;

/**
 * Maps the optional Activity API routes into the host application router.
 */
final class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Activity';

    /**
     * Called before routes are registered.
     *
     * Register any model bindings or pattern based filters.
     */
    public function boot(): void
    {
        $kernel = $this->app->make(HttpKernelContract::class);
        $kernel->prependToMiddlewarePriority(ForceActivityJsonResponse::class);

        parent::boot();
    }

    /**
     * Define the routes for the application.
     */
    public function map(): void
    {
        $this->mapApiRoutes();
        $this->mapWebRoutes();
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     */
    protected function mapWebRoutes(): void {}

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     */
    protected function mapApiRoutes(): void
    {
        if (config('activity.routes.enabled', false) !== true) {
            return;
        }

        $configuredPrefix = config('activity.routes.prefix', 'api/v1');
        $prefix = is_string($configuredPrefix) ? trim($configuredPrefix, '/') : 'api/v1';

        Route::middleware($this->middleware())
            ->prefix($prefix)
            ->name('nvl.activity.')
            ->group(__DIR__.'/../../routes/api.php');
    }

    /**
     * @return list<string>
     */
    private function middleware(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $middleware): mixed => is_string($middleware) ? trim($middleware) : $middleware,
            (array) config('activity.routes.middleware', ['api']),
        ), static fn (mixed $middleware): bool => is_string($middleware) && $middleware !== ''));
    }
}
