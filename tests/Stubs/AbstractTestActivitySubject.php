<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

/**
 * Non-instantiable historical morph target used by relation safety tests.
 */
abstract class AbstractTestActivitySubject extends Model {}
