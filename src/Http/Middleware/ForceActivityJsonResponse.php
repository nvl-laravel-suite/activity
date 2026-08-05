<?php

declare(strict_types=1);

namespace Nvl\Activity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces consistent JSON negotiation for every package-owned API response and failure.
 */
final class ForceActivityJsonResponse
{
    /**
     * Mark the package API request as JSON before validation and authorization execute.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
