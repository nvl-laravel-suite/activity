<?php

declare(strict_types=1);

namespace Nvl\Activity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Nvl\Activity\Builders\ActivityLogBuilder;
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

    public const string DEFAULT_TABLE = 'activity_log';

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
     * Resolve the package-owned activity storage table.
     */
    public function getTable(): string
    {
        $table = config('activity.storage.table', self::DEFAULT_TABLE);

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
}
