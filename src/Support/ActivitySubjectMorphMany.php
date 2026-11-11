<?php

declare(strict_types=1);

namespace Nvl\Activity\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Grammar;

/**
 * Morph-many relation that binds integer subject keys as text, matching the varchar
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
     * Compare an integer parent key as text in `whereHas`, `withCount` and other existence queries.
     *
     * @param  array<int, string>|string  $columns
     * @return Builder<TRelatedModel>
     */
    #[\Override]
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*']): Builder
    {
        if ($this->parent->getKeyType() !== 'int' || $query->getQuery()->from === $parentQuery->getQuery()->from) {
            return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
        }

        $grammar = $query->getQuery()->getGrammar();
        $textType = match ($query->getModel()->getConnection()->getDriverName()) {
            'pgsql' => 'varchar',
            'mysql', 'mariadb' => 'char character set ascii',
            default => 'text',
        };

        $comparison = $grammar->wrap($this->getExistenceCompareKey()).' = cast('.$grammar->wrap($this->getQualifiedParentKeyName()).' as '.$textType.')';

        return $query->select($columns)
            ->whereRaw(new class($comparison) implements Expression
            {
                public function __construct(private readonly string $sql) {}

                public function getValue(Grammar $grammar): string
                {
                    return $this->sql;
                }
            })
            ->where($query->qualifyColumn($this->getMorphType()), $this->morphClass);
    }

    private static function text(mixed $key): mixed
    {
        return is_int($key) ? (string) $key : $key;
    }
}
