<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Pipeline\Budget;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * The step after visibility: the settings document must be valid (R8), then the per-minute limit (FR-021). The limit
 * counts every call to a visible tool, including those refused later, so it runs before any argument is touched.
 *
 * @internal May change without notice.
 */
final class Admission
{
    public static function check(JsonRpcRequest $request, Caller $caller, string $name, Budget $budget): ?JsonRpcResponse
    {
        if (($refused = ResponseBuilder::configRefusal($request, $caller, $name)) !== null) {
            return $refused;
        }

        $retryAfter = $budget->admit();

        if ($retryAfter === null) {
            return null;
        }

        return ResponseBuilder::forReason($request, ReasonCode::RateLimited, ResponseBuilder::receipt($caller, $name, ReasonCode::RateLimited), retryAfter: $retryAfter);
    }
}
