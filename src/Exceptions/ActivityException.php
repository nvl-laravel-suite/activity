<?php

declare(strict_types=1);

namespace Nvl\Activity\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvl\Support\Exceptions\BusinessException;

/**
 * Base domain exception for invalid activity configuration or operations.
 */
class ActivityException extends BusinessException
{
    /**
     * Render safe package failures through the canonical JSON error envelope.
     */
    public function render(Request $request): JsonResponse|bool
    {
        if (! $request->expectsJson()) {
            return false;
        }

        $payload = ['message' => $this->getMessage()];
        $responseCode = $this->responseCode();
        $context = $this->publicContext();

        if ($responseCode !== null) {
            $payload['code'] = $responseCode;
        }

        if ($context !== []) {
            $payload['context'] = $context;
        }

        return response()->json($payload, $this->suggestedStatus());
    }
}
