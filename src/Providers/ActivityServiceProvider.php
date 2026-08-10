<?php

declare(strict_types=1);

namespace Nvl\Activity\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Nvl\Activity\Actions\Activity\QueueActivityLogPurgeAction;
use Nvl\Activity\Console\Commands\ActivityDoctorCommand;
use Nvl\Activity\Console\Commands\PurgeActivityLogsCommand;
use Nvl\Activity\Console\Commands\PurgeSystemActivityLogsCommand;
use Nvl\Activity\Contracts\QueueActivityLogPurgeContract;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Policies\ActivityLogPolicy;
use Nvl\Activity\Services\ActivityDiffBuilder;
use Nvl\Activity\Services\ActivityEntryNormalizer;
use Nvl\Activity\Services\ActivityReadService;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Services\ActivityRelationLoader;
use Nvl\Activity\Services\ActivityTransformService;
use Nvl\Activity\Services\HeadlineRenderer;
use Nvl\Activity\Services\LabelResolver;
use Nvl\Activity\Services\MappingRegistry;
use Nvl\Activity\Services\ModelActivityTimelineService;
use Nvl\Activity\Services\TimelineFilter;
use Nvl\Activity\Support\CauserNormalizer;
use Nvl\Activity\Support\ModelActivityMappingResolver;
use Nvl\Activity\Support\ModelActivityTimelineResolver;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use RuntimeException;

/**
 * Registers the standalone activity package services and extensions.
 */
final class ActivityServiceProvider extends ServiceProvider
{
    protected string $name = 'Activity';

    protected string $nameLower = 'activity';

    /**
     * Register the service provider bindings.
     */
    public function register(): void
    {
        $this->mergeActivityConfiguration();
        config([
            'activitylog.activity_model' => ActivityLog::class,
        ]);

        $this->app->register(RouteServiceProvider::class);

        $this->app->bind(QueueActivityLogPurgeContract::class, QueueActivityLogPurgeAction::class);

        $this->registerSingletons();
    }

    /**
     * Merge nested configuration maps while replacing every consumer list atomically.
     */
    private function mergeActivityConfiguration(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $defaults = require __DIR__.'/../../config/activity.php';
        $configured = $this->app->make(Repository::class)->get('activity', []);

        if (! is_array($defaults) || ! is_array($configured)) {
            throw new RuntimeException('Activity configuration must contain an array.');
        }

        $this->app->make(Repository::class)->set(
            'activity',
            $this->mergeConfigurationValues($defaults, $configured),
        );
    }

    /**
     * Overlay consumer configuration without retaining default numeric-list entries.
     *
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $configured
     * @return array<array-key, mixed>
     */
    private function mergeConfigurationValues(array $defaults, array $configured): array
    {
        if (array_is_list($defaults) || ($configured !== [] && array_is_list($configured))) {
            return $configured;
        }

        $merged = $defaults;

        foreach ($configured as $key => $value) {
            $default = $defaults[$key] ?? null;
            $merged[$key] = is_array($default) && is_array($value)
                ? $this->mergeConfigurationValues($default, $value)
                : $value;
        }

        return $merged;
    }

    /**
     * Boot the application events.
     */
    public function boot(TypeScriptSourceRegistry $typeScriptSources): void
    {
        $typeScriptSources->register(__DIR__.'/..', 'nvl/activity');

        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'activity-skills');
        $this->publishesMigrations([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'activity-migrations');

        $this->registerPolicies();
        if ($this->app->runningInConsole()) {
            $this->registerCommands();
            $this->registerCommandSchedules();
        }

        $this->registerTranslations();
        $this->registerConfig();
        $this->registerModelActivityMappingResolver();
        $this->registerModelActivityTimelineResolver();
        if (config('activity.migrations.enabled', true) === true) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }

    }

    /**
     * Register module artisan commands.
     */
    protected function registerCommands(): void
    {
        $this->commands([
            PurgeActivityLogsCommand::class,
            PurgeSystemActivityLogsCommand::class,
            ActivityDoctorCommand::class,
        ]);
    }

    /**
     * Register authorization policies for the Activity module.
     */
    protected function registerPolicies(): void
    {
        Gate::policy(ActivityLog::class, ActivityLogPolicy::class);
    }

    /**
     * Register scheduled commands for automatic system log cleanup.
     */
    protected function registerCommandSchedules(): void
    {
        if (config('activity.retention.schedule.enabled', false) !== true) {
            return;
        }

        $this->app->booted(function (): void {
            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);

            /** @var int $days */
            $days = config('activity.retention.system_logs_days', 90);
            $configuredTime = config('activity.retention.schedule.time', '02:00');
            $time = is_string($configuredTime) && $configuredTime !== ''
                ? $configuredTime
                : '02:00';

            $schedule->command("nvl:activity:purge-system --days={$days}")
                ->daily()
                ->at($time)
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();
        });
    }

    /**
     * Register module services as singletons.
     */
    private function registerSingletons(): void
    {
        $this->app->singleton(MappingRegistry::class);
        $this->app->singleton(CauserNormalizer::class);
        $this->app->singleton(TimelineFilter::class);
        $this->app->singleton(ActivityReadService::class);
        $this->app->singleton(ActivityRelationLoader::class);

        $this->app->singleton(LabelResolver::class, function (Application $app): LabelResolver {
            return new LabelResolver($app->make(MappingRegistry::class));
        });

        $this->app->singleton(HeadlineRenderer::class, function (Application $app): HeadlineRenderer {
            return new HeadlineRenderer($app->make(LabelResolver::class));
        });

        $this->app->singleton(ActivityDiffBuilder::class, function (Application $app): ActivityDiffBuilder {
            return new ActivityDiffBuilder(
                $app->make(LabelResolver::class),
                $app->make(HeadlineRenderer::class),
            );
        });

        $this->app->singleton(ActivityEntryNormalizer::class, function (Application $app): ActivityEntryNormalizer {
            return new ActivityEntryNormalizer(
                $app->make(HeadlineRenderer::class),
                $app->make(LabelResolver::class),
                $app->make(CauserNormalizer::class),
                $app->make(ActivityDiffBuilder::class),
            );
        });

        $this->app->singleton(ActivityTransformService::class, function (Application $app): ActivityTransformService {
            return new ActivityTransformService(
                $app->make(ActivityEntryNormalizer::class),
                $app->make(ActivityRelationLoader::class),
                $app->make(TimelineFilter::class),
            );
        });

        $this->app->singleton(ModelActivityTimelineService::class, function (Application $app): ModelActivityTimelineService {
            $service = new ModelActivityTimelineService(
                $app->make(ActivityReadService::class),
                $app->make(ActivityTransformService::class),
            );

            ModelActivityTimelineResolver::use($service);

            return $service;
        });

        $this->app->singleton(ActivityRecorder::class);
    }

    /**
     * Attach the provider-owned mapping registry to model trait consumers.
     */
    private function registerModelActivityMappingResolver(): void
    {
        ModelActivityMappingResolver::use($this->app->make(MappingRegistry::class));
    }

    /**
     * Attach the provider-owned timeline service to model trait consumers.
     */
    private function registerModelActivityTimelineResolver(): void
    {
        ModelActivityTimelineResolver::use($this->app->make(ModelActivityTimelineService::class));
    }

    /**
     * Register translations.
     */
    public function registerTranslations(): void
    {
        $langPath = __DIR__.'/../../lang';

        $this->loadTranslationsFrom($langPath, $this->nameLower);
        $this->loadJsonTranslationsFrom($langPath);

        $this->publishes([
            $langPath => lang_path('vendor/'.$this->nameLower),
        ], 'activity-translations');
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $this->publishes([
            __DIR__.'/../../config/activity.php' => config_path('activity.php'),
        ], 'activity-config');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, class-string>
     */
    public function provides(): array
    {
        return [
            MappingRegistry::class,
            ActivityDiffBuilder::class,
            ActivityEntryNormalizer::class,
            ActivityTransformService::class,
            HeadlineRenderer::class,
            LabelResolver::class,
            TimelineFilter::class,
            ActivityReadService::class,
            ActivityRelationLoader::class,
            CauserNormalizer::class,
            ModelActivityTimelineService::class,
            ActivityRecorder::class,
        ];
    }
}
