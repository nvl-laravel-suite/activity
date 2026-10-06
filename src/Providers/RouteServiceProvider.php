<?php

declare(strict_types=1);

namespace Nvl\Activity\Providers;

use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Nvl\Activity\Http\Middleware\ForceActivityJsonResponse;
use Nvl\Support\Traits\RegistersNamespacedResources;

/**
 * Maps the optional Activity API routes into the host application router.
 */
final class RouteServiceProvider extends ServiceProvider
{
    use RegistersNamespacedResources;

    protected string $name = 'Activity';

    /**
     * Called before routes are registered.
     *
     * Register any model bindings or pattern based filters.
     */
    public function boot(): void
    {
        if (config('nvl-activity.routes.enabled', false) !== true) {
            return;
        }
        $kernel = $this->app->make(HttpKernelContract::class);
        $kernel->prependToMiddlewarePriority(ForceActivityJsonResponse::class);
        $this->map();
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
        if (config('nvl-activity.routes.enabled', false) !== true) {
            return;
        }

        $configuredPrefix = config('nvl-activity.routes.prefix', 'nvl/api/v1');
        $prefix = is_string($configuredPrefix) ? trim($configuredPrefix, '/') : 'nvl/api/v1';

        Route::middleware($this->middleware())
            ->prefix($prefix)
            ->name('nvl.activity.')
            ->group(function (): void {
                $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
            });
    }

    /**
     * @return list<string>
     */
    private function middleware(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $middleware): mixed => is_string($middleware) ? trim($middleware) : $middleware,
            (array) config('nvl-activity.routes.middleware', ['api']),
        ), static fn (mixed $middleware): bool => is_string($middleware) && $middleware !== ''));
    }
}
