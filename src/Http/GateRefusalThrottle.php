<?php

declare(strict_types=1);

namespace ContextGate\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs first on the HTTP route. A client (IP plus presented credential) whose requests keep ending in 401 or 403 gets
 * 429 once http.refusals_per_minute is reached, before any Origin, token or database work. A valid credential never
 * accumulates refusals, so other clients behind the same IP cannot lock it out. See RefusalLedger.
 *
 * @internal May change without notice.
 */
final class GateRefusalThrottle
{
    public function handle(Request $request, Closure $next): Response
    {
        if (RefusalLedger::blocked($request)) {
            return response()->json(
                ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32029, 'message' => 'Too many refused requests.']],
                429,
                ['Retry-After' => '60'],
            );
        }

        $response = $next($request);

        if (in_array($response->getStatusCode(), [401, 403], true)) {
            RefusalLedger::record($request);
        }

        return $response;
    }
}
