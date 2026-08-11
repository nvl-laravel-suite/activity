<?php

declare(strict_types=1);

namespace Nvl\Activity\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Nvl\Activity\Builders\ActivityLogBuilder;
use Nvl\Activity\Definitions\Tables\ActivityTables;
use Nvl\Activity\Exceptions\ActivityConfigurationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Custom activity log model using UUID primary keys.
 *
 * @property string $id UUID primary key
 * @property string|null $log_name Activity log namespace
 * @property string $description Human-readable activity description
 * @property string|null $subject_type Polymorphic subject type
 * @property string|null $subject_id Subject identifier
 * @property string|null $causer_type Polymorphic causer type
 * @property string|null $causer_id Causer identifier
 * @property string|null $event Event identifier
 * @property Collection<int|string, mixed>|null $attribute_changes Tracked model attribute changes
 * @property Collection<int|string, mixed>|null $properties Structured activity metadata
 * @property string|null $batch_uuid Caller-owned activity batch UUID
 * @property Carbon|null $created_at Creation timestamp
 * @property Carbon|null $updated_at Last update timestamp
 *
 * @method static ActivityLogBuilder query()
 */
final class ActivityLog extends Activity
{
    use HasUuids;

    public const string DEFAULT_TABLE = ActivityTables::ActivityLog;

    /**
     * The primary key type.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Read tracked changes from the version-specific Spatie storage column.
     *
     * Spatie v5 stores changes in `attribute_changes`; historical v4 rows may
     * still carry them inside `properties`, so reads preserve that legacy data.
     *
     * @return Attribute<Collection<int|string, mixed>|null, never>
     */
    protected function attributeChanges(): Attribute
    {
        return Attribute::get(function (mixed $value): ?Collection {
            $changes = $this->collectionValue($value);

            if ($changes !== null) {
                return $changes;
            }

            return $this->collectionValue($this->getAttribute('properties'))
                ?->only(['attributes', 'old']);
        });
    }

    /**
     * Resolve the package-owned activity storage table.
     */
    public function getTable(): string
    {
        $table = config('activity.storage.table', ActivityTables::ActivityLog);

        if (! is_string($table) || trim($table) === '') {
            throw ActivityConfigurationException::emptyTableName();
        }

        return trim($table);
    }

    /**
     * Resolve the optional package-owned activity storage connection.
     */
    public function getConnectionName(): ?string
    {
        $connection = config('activity.storage.connection');

        if ($connection === null) {
            return parent::getConnectionName();
        }

        if (! is_string($connection) || trim($connection) === '') {
            throw ActivityConfigurationException::invalidConnectionName();
        }

        return trim($connection);
    }

    /**
     * Register the default Activity query builder.
     *
     * @param  QueryBuilder  $query
     */
    public function newEloquentBuilder($query): ActivityLogBuilder
    {
        return new ActivityLogBuilder($query);
    }

    /**
     * Create a query configured for Activity display surfaces.
     */
    public static function forDisplay(): ActivityLogBuilder
    {
        return self::query()->newestFirst();
    }

    /**
     * Create a query configured for a subject timeline surface.
     *
     * @param  string|int|list<string|int>  $subjectId
     */
    public static function forSubjectTimeline(string $subjectType, string|int|array $subjectId): ActivityLogBuilder
    {
        return self::forDisplay()->forSubject($subjectType, $subjectId);
    }

    /**
     * @return Collection<int|string, mixed>|null
     */
    private function collectionValue(mixed $value): ?Collection
    {
        if ($value instanceof Collection) {
            return $value;
        }

        if (is_array($value)) {
            return collect($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? collect($decoded) : null;
    }
}
