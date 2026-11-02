<?php

declare(strict_types=1);

namespace ContextGate\Http;

use ContextGate\Server\ReasonCode;
use ContextGate\Server\Receipt;
use ContextGate\Settings\EffectiveSettings;
use ContextGate\Support\GateCache;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;

/**
 * Counts refused requests in 60 second windows. The only denial is per (client IP, presented credential): at the
 * limit that pair gets 429. A coarse per-IP counter (min(10 x limit, 6000)) only bounds log volume and never denies:
 * above it anonymous refusals are not recorded (one http.throttled summary per IP per window); a refusal attributed
 * to a known token is always recorded. The IP is whatever Laravel's trusted-proxy configuration yields; the IP and
 * the credential hash never reach a receipt.
 *
 * @internal May change without notice.
 */
final class RefusalLedger
{
    private const WINDOW = 60;

    private const BEARER_BYTES = 512;

    private const IP_CAP = 6000;

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

    /** True when the (IP, credential) pair is at the limit and the request is answered 429. */
    public static function blocked(Request $request): bool
    {
        return GateCache::guard(fn (): bool => self::count(self::key($request)) >= self::limit());
    }

    /** True once the IP is at its coarse threshold: anonymous refusals are then not recorded (never a denial). */
    public static function flooded(Request $request): bool
    {
        return GateCache::guard(fn (): bool => self::count(self::ipKey($request)) >= min(self::limit() * 10, self::IP_CAP));
    }

    /** Counts one request that ended in a refusal; writes the single throttle receipt when the credential key trips. */
    public static function record(Request $request): void
    {
        GateCache::guard(function () use ($request): void {
            $key = self::key($request);

            if (self::bump($key) >= self::limit() && GateCache::store()->add(self::tripKey($key), 1, self::WINDOW)) {
                Receipt::write('unknown', null, ReasonCode::HttpThrottled);
            }

            self::increment(self::ipKey($request));
        });
    }

    /** Writes a refusal receipt; an anonymous one is suppressed above the coarse threshold (one summary per window instead). */
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
        if (GateCache::guard(fn (): bool => GateCache::store()->add('gate:refusals-summary:'.self::ip($request), 1, self::WINDOW))) {
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

    /** One-shot marker: the throttle receipt for this key is written once per counter window. */
    private static function tripKey(string $key): string
    {
        return 'gate:refusals-tripped:'.$key;
    }

    /**
     * Credential counter: under the key's lock, so concurrent refusals are counted exactly (FileStore::increment has no lock).
     * block(0) makes one attempt and throws at once when the lock is held: the refusal path never waits (a 3 second wait
     * let anyone holding the key stall every refusal from that IP). On a failed lock the count is kept unlocked, so a race
     * can undercount in that fallback; the request is refused either way.
     * ponytail: one lock per credential key; a busy store with many concurrent refusals will fall back to unlocked counts.
     */
    private static function bump(string $key): int
    {
        $store = GateCache::store()->getStore();

        if (! $store instanceof LockProvider) {
            return self::increment($key);
        }

        try {
            return $store->lock('context-gate:refusals:lock:'.$key, 5)->block(0, static fn (): int => self::increment($key));
        } catch (LockTimeoutException) {
            return self::increment($key);
        }
    }

    /** Same atomic add-then-increment as Budget::reserve, with the lost-TTL guard. A new window forgets the trip marker. */
    private static function increment(string $key): int
    {
        $cache = GateCache::store();
        $added = $cache->add($key, 0, self::WINDOW);
        $total = $cache->increment($key);

        if ($added) {
            $cache->forget(self::tripKey($key));
        }

        if (! is_int($total) || (! $added && $total === 1)) {
            $cache->put($key, 1, self::WINDOW);

            return 1;
        }

        return $total;
    }
}
