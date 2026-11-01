<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

use Carbon\CarbonImmutable;
use Closure;
use ContextGate\Settings\EffectiveSettings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * Stage 10 (R10): per-call truncation, per-session and per-token-day refusal. The per-minute rate limit is not a
 * stage: admit() checks it at call entry, so every call counts, including those refused before stage 10 (FR-021).
 * The values in use are the active profile's (EffectiveSettings, spec 007 FR-017); each can be lowered up to its cap in Caps.
 * A refusal returns the payload with no rows and a code in `flags`; the call is not served.
 *
 * @internal May change without notice.
 */
final class Budget implements Stage
{
    private readonly int $perCall;

    private readonly int $perSession;

    private readonly int $perDay;

    private readonly int $perMinute;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly RateLimiter $limiter,
        private readonly string $caller,
        private readonly string $session,
        private readonly int $sessionTtl,
        ?int $perCall = null,
        ?int $perSession = null,
        ?int $perDay = null,
        ?int $perMinute = null,
    ) {
        $effective = EffectiveSettings::current();
        $this->perCall = min($perCall ?? (int) $effective['firewall.limits.per_call_bytes'], Caps::PER_CALL);
        $this->perSession = min($perSession ?? (int) $effective['firewall.limits.per_session_bytes'], Caps::PER_SESSION);
        $this->perDay = min($perDay ?? (int) $effective['firewall.limits.per_day_bytes'], Caps::PER_DAY);
        $this->perMinute = min($perMinute ?? (int) $effective['firewall.limits.calls_per_minute'], Caps::PER_MINUTE);
    }

    /**
     * FR-021, called once per call at entry. Returns the seconds until the window clears when the call is refused
     * with rate.limited, or null when the call is admitted.
     */
    public function admit(): ?int
    {
        $rateKey = 'gate:'.$this->caller;

        // Hit first, then compare the returned count: two concurrent calls cannot both see limit-1.
        $hits = $this->locked($rateKey, fn (): int => $this->limiter->hit($rateKey, 60));

        if ($hits > $this->perMinute) {
            return $this->limiter->availableIn($rateKey);
        }

        return null;
    }

    /** Seconds from $now to the next 00:00 UTC, the TTL of a day counter. */
    public static function secondsUntilUtcMidnight(CarbonImmutable $now): int
    {
        return $now->addDay()->startOfDay()->getTimestamp() - $now->getTimestamp();
    }

    /** @param Closure(Payload): Payload $next */
    public function handle(Payload $payload, Closure $next): Payload
    {
        $now = CarbonImmutable::now('UTC');
        $sessionKey = 'budget:session:'.$this->session;
        $dayKey = 'budget:day:'.$this->caller.':'.$now->format('Y-m-d');

        [$rows, $truncated] = $this->fit($payload->rows);
        $bytes = $this->size($rows);

        // Reserve atomically: increment first, compare the returned total, roll back on overshoot. The reserved
        // amount is exactly what is served (the per-call truncation happens before).
        $session = $this->reserve($sessionKey, $bytes, $this->sessionTtl);

        if ($session > $this->perSession) {
            $this->release($sessionKey, $bytes, $this->sessionTtl);

            return $this->refuse($payload, BudgetFlag::SESSION);
        }

        $dayTtl = self::secondsUntilUtcMidnight($now);

        try {
            $day = $this->reserve($dayKey, $bytes, $dayTtl);
        } catch (Throwable $e) {
            // A lock timeout here must not keep the session bytes reserved; the call is then not served.
            $this->release($sessionKey, $bytes, $this->sessionTtl);

            throw $e;
        }

        if ($day > $this->perDay) {
            $this->release($dayKey, $bytes, $dayTtl);
            $this->release($sessionKey, $bytes, $this->sessionTtl);

            return $this->refuse($payload, BudgetFlag::DAILY);
        }

        $payload->rows = $rows;
        $payload->bytes = $bytes;

        if ($truncated) {
            $payload->flags[] = BudgetFlag::CALL;
        }

        return $next($payload);
    }

    /**
     * Keeps whole rows, in order, while the JSON stays within the per-call limit. Linear: the encoded list is
     * the brackets, the rows' own lengths and one comma between rows. $factor widens the limit (a work bound).
     *
     * @param list<array<string, mixed>> $rows
     * @return array{list<array<string, mixed>>, bool}
     */
    public function fit(array $rows, int $factor = 1): array
    {
        $kept = [];
        $size = 2;
        $limit = $this->perCall * $factor;

        foreach ($rows as $row) {
            $size += strlen(json_encode($row, JSON_THROW_ON_ERROR)) + ($kept === [] ? 0 : 1);

            if ($size > $limit) {
                return [$kept, true];
            }

            $kept[] = $row;
        }

        return [$kept, false];
    }

    /** @param list<array<string, mixed>> $rows */
    private function size(array $rows): int
    {
        return strlen(json_encode($rows, JSON_THROW_ON_ERROR));
    }

    /**
     * Returns the counter's new total after adding $bytes. When the key vanished between add and increment, the
     * store may have recreated it without a TTL (Redis) or refused (Memcached): put it back with its TTL.
     */
    private function reserve(string $key, int $bytes, int $ttl): int
    {
        return $this->locked($key, function () use ($key, $bytes, $ttl): int {
            $added = $this->cache->add($key, 0, $ttl);
            $total = $this->cache->increment($key, $bytes);

            if (! is_int($total) || (! $added && $total === $bytes)) {
                $this->cache->put($key, $bytes, $ttl);

                return $bytes;
            }

            return $total;
        });
    }

    /** Gives a reservation back; a counter never goes below zero, and one that lost its key gets a TTL again. */
    private function release(string $key, int $bytes, int $ttl): void
    {
        $this->locked($key, function () use ($key, $bytes, $ttl): void {
            $left = $this->cache->decrement($key, $bytes);

            if (! is_int($left) || $left < 0) {
                $this->cache->put($key, 0, $ttl);
            }
        });
    }

    /**
     * Runs one counter's read-modify-write under that key's lock. FileStore::increment reads, adds and writes with no
     * lock, so processes sharing a file store lose updates and overshoot; the lock makes them serial there.
     * ponytail: one lock per counter key, so a refused reservation can briefly over-count another call (refusal only,
     * never an overshoot).
     *
     * @template T
     * @param Closure(): T $fn
     * @return T
     */
    private function locked(string $key, Closure $fn): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $fn();
        }

        return $store->lock('context-gate:budget:lock:'.$key, 5)->block(3, $fn);
    }

    private function refuse(Payload $payload, string $code): Payload
    {
        $payload->rows = [];
        $payload->bytes = 0;
        $payload->flags[] = $code;

        return $payload;
    }
}
