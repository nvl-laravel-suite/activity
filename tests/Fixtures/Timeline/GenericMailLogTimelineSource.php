<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Fixtures\Timeline;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Nvl\Activity\Contracts\ActivityTimelineSource;
use Nvl\Activity\Support\ActivitySubjectReference;

/**
 * Copyable recipe for a host-owned mail table whose category column has its own name.
 *
 * The category column is deployment code: never take it from request input.
 *
 * @implements ActivityTimelineSource<Model>
 */
final readonly class GenericMailLogTimelineSource implements ActivityTimelineSource
{
    /** @param class-string<Model> $model */
    public function __construct(
        private Authenticatable $actor,
        private string $model,
        private string $categoryColumn = 'category',
    ) {
        $prototype = new $model;
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $categoryColumn) !== 1
            || ! Schema::connection($prototype->getConnectionName())->hasColumn($prototype->getTable(), $categoryColumn)) {
            throw new InvalidArgumentException('The mail category column must be a plain column of the mail table.');
        }
    }

    /** @return Builder<Model> */
    public function query(Model|ActivitySubjectReference $subject): Builder
    {
        Gate::forUser($this->actor)->authorize('viewMailLog', [$subject]);
        $reference = self::reference($subject);

        return $this->model::query()
            ->where('notifiable_type', $reference->type)
            ->where('notifiable_id', (string) $reference->id)
            ->select(['id', 'created_at', 'status', $this->categoryColumn]);
    }

    /** @return list<string> */
    public function supersedes(): array
    {
        return [];
    }

    /** Read the subject's morph type and scalar key. */
    private static function reference(Model|ActivitySubjectReference $subject): ActivitySubjectReference
    {
        if ($subject instanceof ActivitySubjectReference) {
            return $subject;
        }
        $key = $subject->getKey();
        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidArgumentException('Timeline subjects require a scalar key.');
        }

        return new ActivitySubjectReference($subject->getMorphClass(), $key);
    }
}
