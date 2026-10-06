<?php

declare(strict_types=1);

namespace Nvl\Activity\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as LaravelResponse;
use Nvl\Activity\Exceptions\ActivityException;
use Nvl\Support\Http\PackageExceptionPayload;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces consistent JSON negotiation for every package-owned API response and failure.
 */
final readonly class ForceActivityJsonResponse
{
    public function __construct(private PackageExceptionPayload $payload) {}

    /**
     * Mark the package API request as JSON before validation and authorization execute.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        try {
            $response = $next($request);
        } catch (ActivityException $exception) {
            return $this->response($exception);
        }
        if (($response instanceof JsonResponse || $response instanceof LaravelResponse)
            && $response->exception instanceof ActivityException) {
            return $this->response($response->exception);
        }

        return $response;
    }

    /** Preserve Activity's established optional code and context fields. */
    private function response(ActivityException $exception): JsonResponse
    {
        $payload = $this->payload->for($exception);
        if ($exception->responseCode() === null) {
            unset($payload['code']);
        }
        if ($payload['context'] instanceof stdClass) {
            unset($payload['context']);
        }

        return new JsonResponse($payload, $exception->suggestedStatus(), $this->payload->headers($exception));
    }
}
