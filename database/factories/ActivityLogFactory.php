<?php

declare(strict_types=1);

namespace Nvl\Activity\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityModelRegistry;

/**
 * Builds ActivityLog fixture rows and their declared package parents.
 *
 * @extends Factory<ActivityLog>
 *
 * @api
 */
final class ActivityLogFactory extends Factory
{
    protected $model = ActivityLog::class;

    /** Resolve the configured native fixture model. */
    public function modelName(): string
    {
        return ActivityModelRegistry::modelClass();
    }

    /**
     * Prepare native parent and owner facts after Laravel expands relationships.
     *
     * @internal
     */
    public function configure(): static
    {
        $expandRelationships = true;

        return $this->state(function () use (&$expandRelationships): array {
            $expandRelationships = $this->expandRelationships;

            return [];
        })->afterMaking(function (ActivityLog $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            FactoryGuard::root($model, 'activity.events');
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<ActivityLog>, mixed>
     */
    public function definition(): array
    {
        return [
            'log_name' => 'factory',
            'event' => 'created',
            'description' => $this->faker->sentence(),
            'properties' => [],
        ];
    }

    /**
     * Associate a persisted host owner using its native morph identity.
     *
     * @api
     */
    public function forOwner(Model $owner): static
    {
        FactoryGuard::parent($owner, ActivityModelRegistry::newInstance());

        return $this->state([
            'subject_type' => $owner->getMorphClass(),
            'subject_id' => (string) FactoryGuard::identifier($owner->getKey()),
        ]);
    }
}
