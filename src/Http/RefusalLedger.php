<?php

declare(strict_types=1);

namespace ContextGate\Http;

use ContextGate\Server\ReasonCode;
use ContextGate\Server\Receipt;
use ContextGate\Settings\EffectiveSettings;
use ContextGate\Support\GateCache;
use Illuminate\Http\Request;

/**
 * Counts refused requests and decides whether a refusal is recorded. Two counters, both with a 60 second window:
 * one per (client IP, presented credential) that can answer 429, and a coarse one per IP that never denies service
 * and only suppresses refusal receipts and token lookups (one http.throttled summary per IP per window). The IP is
 * whatever Laravel's trusted-proxy configuration yields; the IP and the credential hash never reach a receipt.
 *
 * @internal May change without notice.
 */
final class RefusalLedger
{
    private const WINDOW = 60;

    private const BEARER_BYTES = 512;

    private const IP_CAP = 6000;

    /** Per (IP, credential): the first 16 hex chars of sha256 over at most 512 bytes of the bearer, or none. */
    public static function key(Request $request): string
    {
        $bearer = $request->bearerToken();
        $hash = $bearer === null ? 'none' : substr(hash('sha256', substr($bearer, 0, self::BEARER_BYTES)), 0, 16);

        return 'gate:refusals:'.self::ip($request).':'.$hash;
    }

    public static function limit(): int
    {
        return EffectiveSettings::limit('http.refusals_per_minute');
    }

    public static function blocked(Request $request): bool
    {
        return self::count(self::key($request)) >= self::limit();
    }

    /** True once the IP is above the coarse threshold: refusals are then not recorded and the token lookup is skipped. */
    public static function flooded(Request $request): bool
    {
        return self::count(self::ipKey($request)) >= min(self::limit() * 10, self::IP_CAP);
    }

    /** Counts one request that ended in a refusal; writes the single throttle receipt when the credential key trips. */
    public static function record(Request $request): void
    {
        $trips = self::bump(self::key($request)) === self::limit();
        self::bump(self::ipKey($request));

        if ($trips) {
            Receipt::write('unknown', null, ReasonCode::HttpThrottled);
        }
    }

    /** Writes a refusal receipt unless the IP is flooded; then only one summary per IP per window. */
    public static function receipt(Request $request, string $caller, ReasonCode $reason): void
    {
        if (! self::flooded($request)) {
            Receipt::write($caller, null, $reason);
        } elseif (GateCache::store()->add('gate:refusals-summary:'.self::ip($request), 1, self::WINDOW)) {
            Receipt::write('unknown', null, ReasonCode::HttpThrottled);
        }
    }

    private static function ip(Request $request): string
    {
        return $request->ip() ?? 'unknown';
    }

    private static function ipKey(Request $request): string
    {
        return 'gate:refusals-ip:'.self::ip($request);
    }

    private static function count(string $key): int
    {
        return (int) GateCache::store()->get($key, 0);
    }

    /** Same atomic add-then-increment as Budget::reserve, with the lost-TTL guard. */
    private static function bump(string $key): int
    {
        $cache = GateCache::store();
        $added = $cache->add($key, 0, self::WINDOW);
        $total = $cache->increment($key);

        if (! is_int($total) || (! $added && $total === 1)) {
            $cache->put($key, 1, self::WINDOW);

            return 1;
        }

        return $total;
    }
}
