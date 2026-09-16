<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** Detects accidental construction or storage inspection of unregistered subject types. */
final class UnknownActivitySubject extends Model
{
    public static int $constructed = 0;

    protected $table = 'unknown_activity_subjects';

    /** Count every construction attempt made by relation hydration. */
    public function __construct(array $attributes = [])
    {
        self::$constructed++;
        parent::__construct($attributes);
    }
}
