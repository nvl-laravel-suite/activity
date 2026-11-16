<?php

declare(strict_types=1);

namespace Nvl\Activity\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Support\Contracts\TextMatcher;

/** @internal */
final class ActivitySearch
{
    /** Validate a bounded literal term before any search query is changed. */
    public static function validate(string $term): string
    {
        if (! mb_check_encoding($term, 'UTF-8') || mb_strlen($term) > 200 || str_contains($term, "\0")) {
            throw new InvalidArgumentException('Activity search requires at most 200 UTF-8 characters without NUL bytes.');
        }

        return $term;
    }

    /**
     * Match a literal substring through the host's TextMatcher, so case folds beyond ASCII under any collation.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public static function contains(Builder $query, string $column, string $term, bool $or = false): void
    {
        $query->setQuery(app(TextMatcher::class)->contains($query->getQuery(), $column, self::validate($term), boolean: $or ? 'or' : 'and'));
    }
}
