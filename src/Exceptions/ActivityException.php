<?php

declare(strict_types=1);

namespace Nvl\Activity\Exceptions;

use Nvl\Support\Exceptions\BusinessException;

/**
 * @api
 * Base domain exception for invalid activity configuration or operations.
 */
class ActivityException extends BusinessException
{
    /** Return the owning package namespace. */
    public function package(): string
    {
        return 'activity';
    }
}
