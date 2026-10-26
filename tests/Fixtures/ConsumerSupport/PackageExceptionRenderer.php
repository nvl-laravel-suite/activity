<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Fixtures\ConsumerSupport;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvl\Support\Contracts\PackageException;
use Nvl\Support\Contracts\RespondableException;

/** Optional host-selected renderer for explicitly public package failures.
 * @internal
 */
final readonly class PackageExceptionRenderer
{
    public function __construct(private PackageExceptionPayload $payload) {}

    /** Return null whenever the host should choose its own presentation. */
    public function render(PackageException $exception, Request $request): ?JsonResponse
    {
        if (! $request->expectsJson() || ! $exception instanceof RespondableException) {
            return null;
        }

        return new JsonResponse($this->payload->for($exception), $exception->suggestedStatus(), $this->payload->headers($exception));
    }
}
