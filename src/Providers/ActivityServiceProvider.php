<?php

declare(strict_types=1);

namespace Nvl\Activity\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Nvl\Activity\Console\Commands\ActivityDoctorCommand;
use Nvl\Activity\Console\Commands\PurgeActivityLogsCommand;
use Nvl\Activity\Console\Commands\PurgeSystemActivityLogsCommand;
use Nvl\Activity\Ownership\ActivityAdoptionAdapter;
use Nvl\Activity\Ownership\ActivityOwnershipGuard;
use Nvl\Activity\Ownership\ActivityResourceRegistrar;
use Nvl\Activity\Services\ActivityCaptureContext;
use Nvl\Activity\Services\ActivityDiffBuilder;
use Nvl\Activity\Services\ActivityDoctor;
use Nvl\Activity\Services\ActivityModelRegistry;
use Nvl\Activity\Services\ActivityPolicyRegistry;
use Nvl\Activity\Services\ActivityReader;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Services\ActivityRelationLoader;
use Nvl\Activity\Services\ActivityRetentionContext;
use Nvl\Activity\Services\HeadlineRenderer;
use Nvl\Activity\Services\LabelResolver;
use Nvl\Activity\Services\MappingRegistry;
use Nvl\Activity\Services\TimelineFilter;
use Nvl\Activity\Support\ActivityEventPolicy;
use Nvl\Activity\Support\CauserNormalizer;
use Nvl\Activity\Support\ModelActivityMappingResolver;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\Ownership\Contracts\OwnershipIntegratedProvider;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;

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
        $this->app->singleton(ActivityModelRegistry::class);
        $this->app->booting(fn () => $this->app->make(ActivityModelRegistry::class)->prepare());

        $this->app->booted(function (): void {
            if ($this->app->bound(OwnershipAdoptionRegistry::class)) {
                $this->app->make(OwnershipAdoptionRegistry::class)->register('activity', ActivityAdoptionAdapter::class);
            }
        });

        $this->app->singleton(ActivityPolicyRegistry::class, function (): ActivityPolicyRegistry {
            $registry = new ActivityPolicyRegistry;
            $definitions = config('nvl-activity.events', []);
            if (! is_array($definitions)) {
                throw new \InvalidArgumentException('Activity event policies must be an event-keyed map.');
            }
            foreach ($definitions as $event => $options) {
                if (! is_string($event) || ! is_array($options) || array_diff(array_keys($options), ['subject_types', 'actor', 'actor_types', 'change_fields', 'metadata_fields', 'redacted_fields', 'retention_days', 'audience']) !== []) {
                    throw new \InvalidArgumentException('Invalid Activity event configuration.');
                }
                $arguments = [];
                foreach (['subject_types' => 'subjectTypes', 'actor' => 'actor', 'actor_types' => 'actorTypes', 'change_fields' => 'changeFields', 'metadata_fields' => 'metadataFields', 'redacted_fields' => 'redactedFields', 'retention_days' => 'retentionDays', 'audience' => 'audience'] as $key => $parameter) {
                    if (array_key_exists($key, $options)) {
                        $arguments[$parameter] = $options[$key];
                    }
                }
                $registry->register(new ActivityEventPolicy($event, ...$arguments));
            }

            return $registry;
        });
        $this->app->scoped(ActivityRetentionContext::class);
        $this->registerSingletons();
    }

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->app->make(ActivityModelRegistry::class)->activityLogClass();
        (new ActivityResourceRegistrar)->register($this->app->make(OwnershipResourceRegistry::class), $this->app->make('config')->get('nvl-ownership.enabled') === true);

        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'nvl-activity-skills');
        $this->publishesMigrations([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'nvl-activity-migrations');

        if ($this->app->runningInConsole()) {
            $this->registerCommands();
            $this->registerCommandSchedules();
        }

        $this->registerTranslations();
        $this->registerConfig();
        $this->registerModelActivityMappingResolver();
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
        $this->app->scoped(ActivityRelationLoader::class);
        $this->app->scoped(ActivityOwnershipGuard::class);
        $this->app->scopedIf(ActivityCaptureContext::class);

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

        $this->app->scopedIf(ActivityRecorder::class);
        $this->app->scopedIf(ActivityReader::class);
    }

    /**
     * Attach the provider-owned mapping registry to model trait consumers.
     */
    private function registerModelActivityMappingResolver(): void
    {
        ModelActivityMappingResolver::use($this->app->make(MappingRegistry::class));
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
        ], 'nvl-activity-translations');
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $this->publishes([
            __DIR__.'/../../config/nvl-activity.php' => config_path('nvl-activity.php'),
        ], 'nvl-activity-config');
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
            HeadlineRenderer::class,
            LabelResolver::class,
            TimelineFilter::class,
            ActivityRelationLoader::class,
            CauserNormalizer::class,
            ActivityRecorder::class,
        ];
    }
}
