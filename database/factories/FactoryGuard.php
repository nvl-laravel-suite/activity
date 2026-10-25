<?php

declare(strict_types=1);

namespace Nvl\Activity\Database\Factories;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Activity\Ownership\ActivityOwnershipGuard;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/** Validates canonical fixture identities and stamps admitted native ownership. @internal */
final class FactoryGuard
{
    public static function identifier(mixed $key): int|string
    {
        if ((! is_string($key) && ! is_int($key)) || $key === '') {
            throw new InvalidArgumentException('Factory identities require a non-empty native scalar identifier.');
        }

        return $key;
    }

    public static function parent(Model $parent, Model $child): void
    {
        $key = $parent->getKey();
        if (! $parent->exists || (! is_string($key) && ! is_int($key)) || $key === ''
            || $parent->getRawOriginal($parent->getKeyName()) !== $key
            || $parent->getConnection() !== $child->getConnection()) {
            throw new InvalidArgumentException('Factory subjects require unchanged persisted identities on the fixture connection.');
        }
        Container::getInstance()->make(ActivityOwnershipGuard::class)->assertSubject($parent);
    }

    public static function root(Model $model, string $resource): void
    {
        $model->forceFill(Container::getInstance()->make(OwnershipBoundary::class)->attributes($resource));
    }

    private function __construct() {}
}
