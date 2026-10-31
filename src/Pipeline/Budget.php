<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Stage 10 (R10): per-call truncation, per-session and per-token-day refusal, per-minute rate limit.
 * Defaults are the spec values; each can be lowered or raised up to its code cap in Caps (FR-022).
 * A refusal returns the payload with no rows and a code in `flags`; the call is not served.
 */
final class Budget implements Stage
{
    private const DEFAULT_PER_CALL = 16384;

    private const DEFAULT_PER_SESSION = 409600;

    private const DEFAULT_PER_DAY = 5242880;

    private const DEFAULT_PER_MINUTE = 60;

    /** Seconds until the rate limit window clears, set when a call is refused with rate.limited. */
    public ?int $retryAfter = null;

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
        int $perCall = self::DEFAULT_PER_CALL,
        int $perSession = self::DEFAULT_PER_SESSION,
        int $perDay = self::DEFAULT_PER_DAY,
        int $perMinute = self::DEFAULT_PER_MINUTE,
    ) {
        $this->perCall = min($perCall, Caps::PER_CALL);
        $this->perSession = min($perSession, Caps::PER_SESSION);
        $this->perDay = min($perDay, Caps::PER_DAY);
        $this->perMinute = min($perMinute, Caps::PER_MINUTE);
    }

    /** Seconds from $now to the next 00:00 UTC, the TTL of a day counter. */
    public static function secondsUntilUtcMidnight(CarbonImmutable $now): int
    {
        return $now->addDay()->startOfDay()->getTimestamp() - $now->getTimestamp();
    }

    /** @param Closure(Payload): Payload $next */
    public function handle(Payload $payload, Closure $next): Payload
    {
        $this->retryAfter = null;
        $now = CarbonImmutable::now('UTC');
        $rateKey = 'gate:'.$this->caller;
        $sessionKey = 'budget:session:'.$this->session;
        $dayKey = 'budget:day:'.$this->caller.':'.$now->format('Y-m-d');

        if ($this->limiter->tooManyAttempts($rateKey, $this->perMinute)) {
            $this->retryAfter = $this->limiter->availableIn($rateKey);

            return $this->refuse($payload, 'rate.limited');
        }

        $this->limiter->hit($rateKey, 60);

        if ((int) $this->cache->get($sessionKey, 0) >= $this->perSession) {
            return $this->refuse($payload, 'budget.session');
        }

        if ((int) $this->cache->get($dayKey, 0) >= $this->perDay) {
            return $this->refuse($payload, 'budget.daily');
        }

        [$rows, $truncated] = $this->truncate($payload->rows);
        $payload->rows = $rows;
        $payload->bytes = $this->size($rows);

        if ($truncated) {
            $payload->flags[] = 'budget.call';
        }

        $this->spend($sessionKey, $payload->bytes, $this->sessionTtl);
        $this->spend($dayKey, $payload->bytes, self::secondsUntilUtcMidnight($now));

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

    private function spend(string $key, int $bytes, int $ttl): void
    {
        $this->cache->add($key, 0, $ttl);
        $this->cache->increment($key, $bytes);
    }

    private function refuse(Payload $payload, string $code): Payload
    {
        $payload->rows = [];
        $payload->bytes = 0;
        $payload->flags[] = $code;

        return $payload;
    }
}
