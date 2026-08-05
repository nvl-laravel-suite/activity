<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Stubs;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Consumer middleware fixture that deliberately returns a non-JSON response.
 */
final class TestActivityShortCircuitMiddleware
{
    /**
     * Reject the request before it reaches a package controller.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return response('Consumer middleware denied the request.', 403);
    }
}
