<?php

declare(strict_types=1);

namespace Nvl\Activity\Adoption;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use Nvl\Activity\Services\ActivitySchemaInspector;
use Nvl\Support\Adoption\AdoptionAdapter;
use Nvl\Support\Adoption\AdoptionBatch;
use Nvl\Support\Adoption\AdoptionException;
use Nvl\Support\Adoption\AdoptionPlan;
use Nvl\Support\Adoption\AdoptionStep;
use Nvl\Support\Adoption\AdoptionVerification;
use Nvl\Support\Enums\SystemActor;

/**
 * Completes an unowned Spatie v5 audit table in place through nvl:adopt, one checkpointed DDL step at a time.
 *
 * Steps run in order: add batch_uuid, widen PostgreSQL timestamps, add indexes. Each batch takes the PostgreSQL
 * table lock and commits its DDL with its checkpoint, so only transactional DDL is used (plain CREATE INDEX, never
 * CONCURRENTLY). Every batch ensures a fixed list of changes and skips those already present, so counts stay stable
 * on resume. The fingerprint reuses the shared review, which hashes the historical rows and the required columns
 * normalised (batch_uuid excluded, timestamp precision ignored, indexes left out), so the adapter's own DDL never
 * invalidates the reviewed plan between apply and resume.
 *
 * @internal Hosts run it through `php artisan nvl:adopt activity`.
 */
final class ActivitySchemaAdoptionAdapter extends AdoptionAdapter
{
    public const string Version = '1';

    /**
     * @param  array<string, mixed>  $options  Activity adoption takes no manifest options
     */
    public function __construct(
        Authenticatable|SystemActor $actor,
        array $options,
        private readonly ActivitySchemaInspector $schema,
    ) {
        if ($options !== []) {
            throw new InvalidArgumentException('Activity adoption takes no manifest options.');
        }
        parent::__construct($actor, $options);
    }

    public function connection(): Connection
    {
        return $this->schema->target()[0];
    }

    public function authorize(string $phase): void
    {
        $this->schema->authorize($this->actor);
    }

    public function plan(): AdoptionPlan
    {
        [$connection, $table] = $this->schema->target();
        $review = $this->schema->review($connection, $table);
        $identity = ['connection' => $connection->getName(), 'table' => $table];
        $steps = $this->steps();
        $expected = [];
        foreach ($steps as $step) {
            $expected[$step->id] = count($this->changes($connection, $table, $step->id));
        }

        return new AdoptionPlan(
            package: 'activity',
            adapterVersion: self::Version,
            fingerprint: AdoptionPlan::digest([
                'adapter' => self::Version,
                'history' => $review['fingerprint'],
            ]),
            source: $identity,
            destination: $identity,
            optionsDigest: AdoptionPlan::digest($this->options),
            steps: $steps,
            expected: $expected,
            collisions: $review['conflicts'],
        );
    }

    public function nextBatch(AdoptionStep $step, ?array $cursor, int $limit): AdoptionBatch
    {
        [$connection, $table] = $this->schema->target();
        $this->schema->lock($connection, $table);
        $after = $cursor === null ? null : (string) $cursor['name'];
        $remaining = array_values(array_filter(
            $this->changes($connection, $table, $step->id),
            static fn (array $change): bool => $after === null || strcmp($change['name'], $after) > 0,
        ));
        $page = array_slice($remaining, 0, $limit);
        $last = $page === [] ? null : $page[array_key_last($page)];

        return new AdoptionBatch($step->id, $cursor, $last === null ? $cursor : $step->cursorFrom($last), $page, count($remaining) <= $limit);
    }

    public function writeBatch(AdoptionBatch $batch): ?string
    {
        [$connection, $table] = $this->schema->target();
        $state = $this->schema->state($connection, $table);
        if ($state['conflicts'] !== []) {
            throw new AdoptionException('Activity adoption has unresolved conflicts: '.implode(', ', $state['conflicts']).'.');
        }
        $names = array_values(array_filter(array_column($batch->rows, 'name'), is_string(...)));
        match ($batch->step) {
            'batch_uuid' => $this->schema->addColumnsAndIndexes($connection, $table, $state['add_batch_uuid'] && $names !== [], []),
            'timestamps' => $this->schema->widenTimestamps($connection, $table, array_values(array_intersect($state['widen_timestamps'], $names))),
            'indexes' => $this->schema->addColumnsAndIndexes($connection, $table, false, array_intersect_key($state['missing_indexes'], array_flip($names))),
            default => throw new AdoptionException("Activity adoption has no step [{$batch->step}]."),
        };

        return null;
    }

    public function verify(AdoptionPlan $plan): AdoptionVerification
    {
        [$connection, $table] = $this->schema->target();
        $review = $this->schema->review($connection, $table);
        $schema = $connection->getSchemaBuilder();
        $outstanding = (int) $review['add_batch_uuid'] + count($review['widen_timestamps']) + count($review['missing_indexes']);

        return new AdoptionVerification($outstanding, 0, 0, AdoptionPlan::digest([
            'history' => $review['fingerprint'],
            'columns' => $schema->getColumns($table),
            'indexes' => $schema->getIndexes($table),
        ]), $review['conflicts']);
    }

    /**
     * @return list<AdoptionStep>
     */
    private function steps(): array
    {
        return [
            new AdoptionStep('batch_uuid', [], ['name']),
            new AdoptionStep('timestamps', ['batch_uuid'], ['name']),
            new AdoptionStep('indexes', ['timestamps'], ['name']),
        ];
    }

    /**
     * Return the fixed, name-ordered changes a step ensures; present ones are skipped when written.
     *
     * @return list<array{name: string, columns?: list<string>}>
     */
    private function changes(Connection $connection, string $table, string $step): array
    {
        $changes = match ($step) {
            'batch_uuid' => [['name' => 'batch_uuid']],
            'timestamps' => $connection->getDriverName() === 'pgsql'
                ? array_map(static fn (string $column): array => ['name' => $column], ActivitySchemaInspector::TIMESTAMPS)
                : [],
            'indexes' => array_map(
                static fn (string $name, array $columns): array => ['name' => $name, 'columns' => $columns],
                array_keys($this->schema->requiredIndexes($connection, $table)),
                $this->schema->requiredIndexes($connection, $table),
            ),
            default => throw new AdoptionException("Activity adoption has no step [{$step}]."),
        };
        usort($changes, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

        return $changes;
    }
}
