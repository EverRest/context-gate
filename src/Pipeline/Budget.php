<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

use Carbon\CarbonImmutable;
use Closure;
use ContextGate\Settings\EffectiveSettings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Stage 10 (R10): per-call truncation, per-session and per-token-day refusal. The per-minute rate limit is not a
 * stage: admit() checks it at call entry, so every call counts, including those refused before stage 10 (FR-021).
 * The values in use are the active profile's (EffectiveSettings, spec 007 FR-017); each can be lowered up to its cap in Caps.
 * A refusal returns the payload with no rows and a code in `flags`; the call is not served.
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
        $effective = EffectiveSettings::for((array) config('context-gate', []), (string) config('app.env', ''));
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
        if ($this->limiter->hit($rateKey, 60) > $this->perMinute) {
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

        [$rows, $truncated] = $this->truncate($payload->rows);
        $bytes = $this->size($rows);

        // Reserve atomically: increment first, compare the returned total, roll back on overshoot. The reserved
        // amount is exactly what is served (the per-call truncation happens before).
        $session = $this->reserve($sessionKey, $bytes, $this->sessionTtl);

        if ($session > $this->perSession) {
            $this->cache->decrement($sessionKey, $bytes);

            return $this->refuse($payload, BudgetFlag::SESSION);
        }

        if ($this->reserve($dayKey, $bytes, self::secondsUntilUtcMidnight($now)) > $this->perDay) {
            $this->cache->decrement($dayKey, $bytes);
            $this->cache->decrement($sessionKey, $bytes);

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
     * Keeps whole rows, in order, while the JSON stays within the per-call limit.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{list<array<string, mixed>>, bool}
     */
    private function truncate(array $rows): array
    {
        $kept = [];

        foreach ($rows as $row) {
            $kept[] = $row;

            if ($this->size($kept) > $this->perCall) {
                array_pop($kept);

                return [$kept, true];
            }
        }

        return [$kept, false];
    }

    /** @param list<array<string, mixed>> $rows */
    private function size(array $rows): int
    {
        return strlen(json_encode($rows, JSON_THROW_ON_ERROR));
    }

    /** Returns the counter's new total after adding $bytes. */
    private function reserve(string $key, int $bytes, int $ttl): int
    {
        $this->cache->add($key, 0, $ttl);

        return (int) $this->cache->increment($key, $bytes);
    }

    private function refuse(Payload $payload, string $code): Payload
    {
        $payload->rows = [];
        $payload->bytes = 0;
        $payload->flags[] = $code;

        return $payload;
    }
}
