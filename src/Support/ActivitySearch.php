<?php

declare(strict_types=1);

namespace Nvl\Activity\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use InvalidArgumentException;

/** @internal */
final readonly class ActivitySearch implements Expression
{
    private function __construct(private string $column) {}

    /** Validate a bounded literal term before any search query is changed. */
    public static function validate(string $term): string
    {
        if (! mb_check_encoding($term, 'UTF-8') || mb_strlen($term) > 200 || str_contains($term, "\0")) {
            throw new InvalidArgumentException('Activity search requires at most 200 UTF-8 characters without NUL bytes.');
        }

        return $term;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public static function contains(Builder $query, string $column, string $term, bool $or = false): void
    {
        $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], self::validate($term)).'%';
        $query->whereRaw(new self($column), [$needle], $or ? 'or' : 'and');
    }

    /** Compile only the grammar-quoted identifier; the literal term remains bound. */
    public function getValue(Grammar $grammar): string
    {
        $wrapped = $grammar->wrap($this->column);
        $operator = $grammar instanceof PostgresGrammar ? 'ILIKE' : 'LIKE';

        return "{$wrapped} {$operator} ? ESCAPE '!'";
    }
}
