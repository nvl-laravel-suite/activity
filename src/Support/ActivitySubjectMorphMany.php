<?php

declare(strict_types=1);

namespace Nvl\Activity\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Grammar;
use LogicException;

/**
 * Morph-many relation that binds subject keys as text, matching the varchar
 * `subject_id` column so its index stays usable and PostgreSQL accepts the comparison.
 *
 * @internal
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphMany<TRelatedModel, TDeclaringModel>
 */
final class ActivitySubjectMorphMany extends MorphMany
{
    /** {@inheritDoc} */
    #[\Override]
    public function addConstraints(): void
    {
        if (self::$constraints) {
            $this->getRelationQuery()->where($this->morphType, $this->morphClass)
                ->where($this->foreignKey, '=', self::text($this->getParentKey()))
                ->whereNotNull($this->foreignKey);
        }
    }

    /** {@inheritDoc} */
    #[\Override]
    public function addEagerConstraints(array $models): void
    {
        $this->whereInEager('whereIn', $this->foreignKey, array_map(self::text(...), $this->getKeys($models, $this->localKey)), $this->getRelationQuery());
        $this->getRelationQuery()->where($this->morphType, $this->morphClass);
    }

    /**
     * Compare parent keys as text in `whereHas`, `withCount` and other existence queries.
     *
     * @param  array<int, string>|string  $columns
     * @return Builder<TRelatedModel>
     */
    #[\Override]
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*']): Builder
    {
        if ($query->getModel()->getConnection()->getName() !== $parentQuery->getModel()->getConnection()->getName()) {
            throw new LogicException('Cross-connection Activity subject existence queries are not supported.');
        }

        $grammar = $query->getQuery()->getGrammar();
        $textType = $query->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'varchar' : 'text';

        if ($query->getQuery()->from === $parentQuery->getQuery()->from) {
            $hash = $this->getRelationCountHash();
            $query->from($query->getModel()->getTable().' as '.$hash);
            $query->getModel()->setTable($hash);
            $compareKey = $hash.'.'.$this->getForeignKeyName();
            $morphTypeColumn = $hash.'.'.$this->getMorphType();
        } else {
            $compareKey = $this->getExistenceCompareKey();
            $morphTypeColumn = $query->qualifyColumn($this->getMorphType());
        }

        $comparison = $grammar->wrap($compareKey).' = cast('.$grammar->wrap($this->getQualifiedParentKeyName()).' as '.$textType.')';

        return $query->select($columns)
            ->whereRaw(new class($comparison) implements Expression
            {
                public function __construct(private readonly string $sql) {}

                public function getValue(Grammar $grammar): string
                {
                    return $this->sql;
                }
            })
            ->where($morphTypeColumn, $this->morphClass);
    }

    private static function text(mixed $key): mixed
    {
        return is_int($key) ? (string) $key : $key;
    }
}
