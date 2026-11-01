<?php

declare(strict_types=1);

namespace ContextGate\Http;

use ContextGate\Server\ReasonCode;
use ContextGate\Server\Receipt;
use ContextGate\Settings\EffectiveSettings;
use ContextGate\Support\GateCache;
use Illuminate\Http\Request;

/**
 * Counts refused requests, both counters with a 60 second window: one per (client IP, presented credential) and a
 * hard cap per IP (min(10 x limit, 6000)). Either answers 429, except to a credential that fully authenticated within
 * the last 600 seconds (marker gate:valid:<hash16>; it skips only the throttle, never authentication). Above the IP
 * cap anonymous refusals are not recorded (one http.throttled summary per IP per window); a refusal attributed to a
 * known token is always recorded. The IP is whatever Laravel's trusted-proxy configuration yields; the IP and the
 * credential hash never reach a receipt.
 *
 * @internal May change without notice.
 */
final class RefusalLedger
{
    private const WINDOW = 60;

    private const BEARER_BYTES = 512;

    private const IP_CAP = 6000;

    private const VALID_TTL = 600;

    /** First 16 hex chars of sha256 over at most 512 bytes of the bearer, or none. */
    private static function hash(Request $request): string
    {
        $bearer = $request->bearerToken();

        return $bearer === null ? 'none' : substr(hash('sha256', substr($bearer, 0, self::BEARER_BYTES)), 0, 16);
    }

    /** Per (IP, credential). */
    public static function key(Request $request): string
    {
        return 'gate:refusals:'.self::ip($request).':'.self::hash($request);
    }

    public static function limit(): int
    {
        return EffectiveSettings::limit('http.refusals_per_minute');
    }

    /** A Sanctum token is `<id>|<40+ alphanumeric>`; anything else cannot resolve to a token row. */
    public static function looksLikeToken(string $bearer): bool
    {
        return strlen($bearer) <= self::BEARER_BYTES && preg_match('/^\d+\|[A-Za-z0-9]{40,}$/', $bearer) === 1;
    }

    /** Called once a request has fully passed authentication; stores a hash prefix only. */
    public static function markValid(Request $request): void
    {
        if ($request->bearerToken() !== null) {
            GateCache::store()->put('gate:valid:'.self::hash($request), 1, self::VALID_TTL);
        }
    }

    /**
     * True when the request is answered 429: its (IP, credential) pair is at the limit, or the IP is at its cap. A
     * recently valid credential is never denied here; it still goes through full authentication.
     */
    public static function blocked(Request $request): bool
    {
        if ($request->bearerToken() !== null && GateCache::store()->has('gate:valid:'.self::hash($request))) {
            return false;
        }

        if (self::flooded($request)) {
            self::summary($request);

            return true;
        }

        return self::count(self::key($request)) >= self::limit();
    }

    /** True once the IP is at its cap: anonymous refusals are then not recorded. */
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

    /** Writes a refusal receipt; an anonymous one is suppressed above the IP cap (one summary per window instead). */
    public static function receipt(Request $request, string $caller, ReasonCode $reason): void
    {
        if ($caller !== 'unknown' || ! self::flooded($request)) {
            Receipt::write($caller, null, $reason);
        } else {
            self::summary($request);
        }
    }

    private static function summary(Request $request): void
    {
        if (GateCache::store()->add('gate:refusals-summary:'.self::ip($request), 1, self::WINDOW)) {
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
