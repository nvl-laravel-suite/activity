<?php

declare(strict_types=1);

namespace Nvl\Activity\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Nvl\Activity\Actions\Activity\ListActivitiesAction;
use Nvl\Activity\Actions\Activity\ListActivityCauserSuggestionsAction;
use Nvl\Activity\Actions\Activity\QueueActivityLogPurgeAction;
use Nvl\Activity\Console\Commands\ActivityDoctorCommand;
use Nvl\Activity\Console\Commands\PurgeActivityLogsCommand;
use Nvl\Activity\Console\Commands\PurgeSystemActivityLogsCommand;
use Nvl\Activity\Contracts\ActivityReadContract;
use Nvl\Activity\Contracts\ActivityRecorderContract;
use Nvl\Activity\Contracts\ActivityTenantWorklist;
use Nvl\Activity\Contracts\ListActivitiesContract;
use Nvl\Activity\Contracts\ListActivityCauserSuggestionsContract;
use Nvl\Activity\Contracts\QueueActivityLogPurgeContract;
use Nvl\Activity\Events\ActivityLogPurgeQueued;
use Nvl\Activity\Events\ActivityLogPurgeQueuedEvent;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Ownership\ActivityAdoptionAdapter;
use Nvl\Activity\Ownership\ActivityOwnershipGuard;
use Nvl\Activity\Policies\ActivityLogPolicy;
use Nvl\Activity\Services\ActivityDiffBuilder;
use Nvl\Activity\Services\ActivityDoctor;
use Nvl\Activity\Services\ActivityEntryNormalizer;
use Nvl\Activity\Services\ActivityReadService;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Services\ActivityRelationLoader;
use Nvl\Activity\Services\ActivityTransformService;
use Nvl\Activity\Services\ConfiguredActivityTenantWorklist;
use Nvl\Activity\Services\HeadlineRenderer;
use Nvl\Activity\Services\LabelResolver;
use Nvl\Activity\Services\MappingRegistry;
use Nvl\Activity\Services\ModelActivityTimelineService;
use Nvl\Activity\Services\TimelineFilter;
use Nvl\Activity\Support\CauserNormalizer;
use Nvl\Activity\Support\ModelActivityMappingResolver;
use Nvl\Activity\Support\ModelActivityTimelineResolver;
use Nvl\Activity\Tenancy\ActivityResourceRegistrar;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Events\EventAliases;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\Ownership\Contracts\OwnershipIntegratedProvider;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Providers\TenantServiceProvider;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;

/**
 * Registers the standalone activity package services and extensions.
 */
final class ActivityServiceProvider extends ServiceProvider implements OwnershipIntegratedProvider
{
    use MergesPackageConfiguration;
    use RegistersNamespacedResources;

    protected string $name = 'Activity';

    protected string $nameLower = 'activity';

    /**
     * Register the service provider bindings.
     */
    public function register(): void
    {
        PackageDoctorContributor::register($this->app, 'nvl/activity', fn (): array => $this->app->make(ActivityDoctor::class)->inspect());

        $this->mergePackageConfiguration(__DIR__.'/../../config/nvl-activity.php', 'activity');
        if (config('nvl-activity.adoption.spatie') === true) {
            config(['activitylog.activity_model' => ActivityLog::class]);
        }

        $this->app->register(TenantServiceProvider::class);
        $this->app->register(RouteServiceProvider::class);
        (new ActivityResourceRegistrar)->register($this->app->make(TenantResourceRegistry::class));
        $this->app->booted(function (): void {
            if ($this->app->bound(OwnershipAdoptionRegistry::class)) {
                $this->app->make(OwnershipAdoptionRegistry::class)->register('activity', ActivityAdoptionAdapter::class);
            }
            if ($this->app->bound(TenantAdoptionRegistry::class)) {
                (new ActivityResourceRegistrar)->register($this->app->make(TenantResourceRegistry::class), $this->app->make(TenantAdoptionRegistry::class));
            }
        });

        $this->app->bindIf(ListActivitiesContract::class, ListActivitiesAction::class);
        $this->app->bindIf(ListActivityCauserSuggestionsContract::class, ListActivityCauserSuggestionsAction::class);
        $this->app->bindIf(QueueActivityLogPurgeContract::class, QueueActivityLogPurgeAction::class);
        $this->app->bindIf(ActivityTenantWorklist::class, ConfiguredActivityTenantWorklist::class);

        $this->registerSingletons();
    }

    /**
     * Boot the application events.
     */
    public function boot(TypeScriptSourceRegistry $typeScriptSources): void
    {
        (new \Nvl\Activity\Ownership\ActivityResourceRegistrar)->register($this->app->make(OwnershipResourceRegistry::class), $this->app->make('config')->get('nvl-ownership.enabled') === true);
        $this->app->make(EventAliases::class)->register(ActivityLogPurgeQueued::class, ActivityLogPurgeQueuedEvent::class);
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
        if (config('nvl-activity.migrations.enabled', true) === true) {
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
        if (config('nvl-activity.retention.schedule.enabled', false) !== true) {
            return;
        }

        $this->app->booted(function (): void {
            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);

            /** @var int $days */
            $days = config('nvl-activity.retention.system_logs_days', 90);
            $configuredTime = config('nvl-activity.retention.schedule.time', '02:00');
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
        $this->app->scopedIf(ActivityReadService::class);
        $this->app->scopedIf(ActivityReadContract::class, static fn (Application $app): ActivityReadContract => $app->make(ActivityReadService::class));
        $this->app->scoped(ActivityRelationLoader::class);
        $this->app->scoped(ActivityOwnershipGuard::class);

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

        $this->app->scoped(ActivityTransformService::class, function (Application $app): ActivityTransformService {
            return new ActivityTransformService(
                $app->make(ActivityEntryNormalizer::class),
                $app->make(ActivityRelationLoader::class),
                $app->make(TimelineFilter::class),
            );
        });

        $this->app->scoped(ModelActivityTimelineService::class, function (Application $app): ModelActivityTimelineService {
            $service = new ModelActivityTimelineService(
                $app->make(ActivityReadService::class),
                $app->make(ActivityTransformService::class),
            );

            return $service;
        });

        $this->app->scopedIf(ActivityRecorder::class);
        $this->app->scopedIf(ActivityRecorderContract::class, static fn (Application $app): ActivityRecorderContract => $app->make(ActivityRecorder::class));
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
        ModelActivityTimelineResolver::resolveUsing(fn (): ModelActivityTimelineService => $this->app->make(ModelActivityTimelineService::class));
    }

    /**
     * Register translations.
     */
    public function registerTranslations(): void
    {
        $langPath = __DIR__.'/../../lang';

        $this->app->make(GlobalNames::class)->translations('activity', $langPath, $this->app->make('translation.loader'));

        $this->publishes([
            $langPath => lang_path('vendor/nvl-'.$this->nameLower),
        ], 'activity-translations');
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $this->publishes([
            __DIR__.'/../../config/nvl-activity.php' => config_path('nvl-activity.php'),
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
            ListActivitiesContract::class,
            ListActivityCauserSuggestionsContract::class,
            QueueActivityLogPurgeContract::class,
            ActivityReadContract::class,
            ActivityRecorderContract::class,
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
