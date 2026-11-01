<?php

declare(strict_types=1);

namespace ContextGate\Http;

use Closure;
use ContextGate\Server\ReasonCode;
use ContextGate\Server\Receipt;
use ContextGate\Settings\EffectiveSettings;
use ContextGate\Support\GateCache;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs first on the HTTP route. Counts the requests of one client (IP) that the gate itself refuses with 401 or 403,
 * per minute; once http.refusals_per_minute is reached the client gets 429 before any Origin, token or database work.
 * One http.throttled receipt is written per client per window, when the limit trips; the IP never reaches the log.
 * Allowed requests are not counted (the per-minute call limit covers those).
 *
 * @internal May change without notice.
 */
final class GateRefusalThrottle
{
    private const WINDOW = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $key = self::key($request);
        $cache = GateCache::store();

        if (self::count($request) >= self::limit()) {
            return response()->json(
                ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'Unauthenticated.']],
                429,
                ['Retry-After' => (string) self::WINDOW],
            );
        }

        $response = $next($request);

        if (in_array($response->getStatusCode(), [401, 403], true)) {
            // Same atomic add-then-increment as Budget::reserve, with the lost-TTL guard.
            $added = $cache->add($key, 0, self::WINDOW);
            $total = $cache->increment($key);

            if (! is_int($total) || (! $added && $total === 1)) {
                $cache->put($key, 1, self::WINDOW);
                $total = 1;
            }

            if ($total === self::limit()) {
                Receipt::write('unknown', null, ReasonCode::HttpThrottled);
            }
        }

        return $response;
    }

    /** Refusals counted for this client in the current window. */
    public static function count(Request $request): int
    {
        return (int) GateCache::store()->get(self::key($request), 0);
    }

    public static function limit(): int
    {
        return EffectiveSettings::limit('http.refusals_per_minute');
    }

    private static function key(Request $request): string
    {
        return 'gate:refusals:'.($request->ip() ?? 'unknown');
    }
}
