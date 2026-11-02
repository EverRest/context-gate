<?php

declare(strict_types=1);

namespace ContextGate\Http;

use Closure;
use ContextGate\Server\Failure;
use ContextGate\Server\ReasonCode;
use ContextGate\Server\Receipt;
use ContextGate\Support\StoreUnavailable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs first on the HTTP route. A client (IP plus presented credential) whose requests keep ending in 401 or 403 gets
 * 429 once http.refusals_per_minute is reached, before any Origin, token or database work. No other client is ever
 * denied because of it. See RefusalLedger.
 *
 * @internal May change without notice.
 */
final class GateRefusalThrottle
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $blocked = RefusalLedger::blocked($request);
        } catch (StoreUnavailable $e) {
            // The throttle cannot count, so nothing is served: refused before any token work, with no hint about why.
            $this->failed($e);

            return response()->json(
                ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => 'Service unavailable.']],
                503,
            );
        }

        if ($blocked) {
            return response()->json(
                ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32029, 'message' => 'Too many refused requests.']],
                429,
                ['Retry-After' => '60'],
            );
        }

        $response = $next($request);

        if (in_array($response->getStatusCode(), [401, 403], true)) {
            try {
                RefusalLedger::record($request);
            } catch (StoreUnavailable $e) {
                // The request is already refused; its status stands, and the failure is still receipted.
                $this->failed($e);
            }
        }

        return $response;
    }

    private function failed(StoreUnavailable $e): void
    {
        $reference = Receipt::write('unknown', null, ReasonCode::StoreUnavailable);
        Failure::note($e, $reference);
    }
}
