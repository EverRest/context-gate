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

    /** The counter key per (IP, credential), in the package namespace. */
    public static function key(Request $request): string
    {
        return GateCache::key(self::credentialSuffix($request));
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
        return GateCache::guard(fn (): bool => self::count(self::credentialSuffix($request)) >= self::limit());
    }

    /** True once the IP is at its coarse threshold: anonymous refusals are then not recorded (never a denial). */
    public static function flooded(Request $request): bool
    {
        return GateCache::guard(fn (): bool => self::count(self::ipSuffix($request)) >= min(self::limit() * 10, self::IP_CAP));
    }

    /** Counts one request that ended in a refusal; writes the single throttle receipt when the credential key trips. */
    public static function record(Request $request): void
    {
        GateCache::guard(function () use ($request): void {
            $trip = self::tripSuffix($request);

            if (self::bump(self::credentialSuffix($request), $trip) >= self::limit() && GateCache::store()->add(GateCache::key($trip), 1, self::WINDOW)) {
                Receipt::write('unknown', null, ReasonCode::HttpThrottled);
            }

            self::increment(self::ipSuffix($request));
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
        $key = GateCache::key('refusals:summary:'.self::ip($request));

        if (GateCache::guard(fn (): bool => GateCache::store()->add($key, 1, self::WINDOW))) {
            Receipt::write('unknown', null, ReasonCode::HttpThrottled);
        }
    }

    private static function ip(Request $request): string
    {
        return $request->ip() ?? 'unknown';
    }

    private static function credentialSuffix(Request $request): string
    {
        return 'refusals:cred:'.self::ip($request).':'.self::hash($request);
    }

    private static function ipSuffix(Request $request): string
    {
        return 'refusals:ip:'.self::ip($request);
    }

    /** One-shot marker: the throttle receipt for this credential is written once per counter window. */
    private static function tripSuffix(Request $request): string
    {
        return 'refusals:tripped:'.self::ip($request).':'.self::hash($request);
    }

    private static function count(string $suffix): int
    {
        return (int) GateCache::store()->get(GateCache::key($suffix), 0);
    }

    /**
     * Credential counter: under the key's lock, so concurrent refusals are counted exactly (FileStore::increment has no lock).
     * block(0) makes one attempt and throws at once when the lock is held: the refusal path never waits (a 3 second wait
     * let anyone holding the key stall every refusal from that IP). On a failed lock the count is kept unlocked, so a race
     * can undercount in that fallback; the request is refused either way.
     * ponytail: one lock per credential key; a busy store with many concurrent refusals will fall back to unlocked counts.
     */
    private static function bump(string $suffix, string $trip): int
    {
        $store = GateCache::store()->getStore();

        if (! $store instanceof LockProvider) {
            return self::increment($suffix, $trip);
        }

        try {
            return $store->lock(GateCache::lockKey($suffix), 5)->block(0, static fn (): int => self::increment($suffix, $trip));
        } catch (LockTimeoutException) {
            return self::increment($suffix, $trip);
        }
    }

    /**
     * Add-then-increment: add creates the counter with its TTL, increment counts atomically on Redis. A new window
     * forgets the trip marker of the credential it counts ($trip), when there is one.
     *
     * ponytail: no re-put of a counter that lost its TTL. A put after an unlocked increment overwrites the increments
     * that landed in between (154 of 160 refusals counted on Redis in the concurrency run). Ceiling: a counter that expires between add and
     * increment stays without a TTL. Upgrade: a store-level increment that sets the TTL, or a waiting lock here.
     */
    private static function increment(string $suffix, ?string $trip = null): int
    {
        $cache = GateCache::store();
        $key = GateCache::key($suffix);
        $added = $cache->add($key, 0, self::WINDOW);
        $total = $cache->increment($key);

        if ($added && $trip !== null) {
            $cache->forget(GateCache::key($trip));
        }

        return is_int($total) ? $total : 1;
    }
}
