<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Nvl\Activity\Builders\ActivityLogBuilder;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Support\Models\ConfiguredModelRegistration;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;

/** Resolves the deployment's Activity root without changing its persisted identity.
 * @api
 */
final class ActivityModelRegistry
{
    /** @var ConfiguredModelRegistration<ActivityLog> */
    private readonly ConfiguredModelRegistration $registration;

    private bool $storageValidated = false;

    public function __construct(Repository $configuration, OwnerRegistry $identities)
    {
        $this->registration = new ConfiguredModelRegistration($configuration, $identities, 'nvl-activity.models.activity_log', ActivityLog::class, 'activity.events', static function (string $class): void {
            if ($class::ownershipDefinition() != ActivityLog::ownershipDefinition()) {
                throw new OwnershipConfigurationInvalid('Activity replacements must retain the native ownership declaration.');
            }
        });
    }

    /** Freeze the selected class before native resource compilation.
     * @internal
     *
     * @return class-string<ActivityLog>
     */
    public function prepare(): string
    {
        return $this->registration->prepare();
    }

    /** @return class-string<ActivityLog> */
    public function activityLogClass(): string
    {
        $class = $this->registration->modelClass();
        if (! $this->storageValidated) {
            $model = new $class;
            $base = new ActivityLog;
            if ($model->getTable() !== $base->getTable() || $model->getConnection() !== $base->getConnection()) {
                throw new OwnershipConfigurationInvalid('Activity replacement storage must use the package-configured table and connection.');
            }
            $this->storageValidated = true;
        }

        return $class;
    }

    /** @internal
     * @return class-string<ActivityLog>
     */
    public static function modelClass(): string
    {
        return Container::getInstance()->make(self::class)->activityLogClass();
    }

    /** Construct the configured native model.
     * @internal
     */
    public static function newInstance(): ActivityLog
    {
        $class = self::modelClass();

        return new $class;
    }

    /** @internal */
    public static function query(): ActivityLogBuilder
    {
        return self::newInstance()->newQuery();
    }
}
