<?php

declare(strict_types=1);

namespace ContextGate\Privacy;

use ContextGate\Support\GateCache;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * Keyed pseudonyms (R4, R5). A session key lives in process memory. Day and week keys live in the application
 * cache, encrypted, until their absolute expiry (FR-008, FR-009). A day or week key is bound to the caller, so
 * one caller cannot resolve another caller's pseudonym. No other scope exists, so nothing outlives seven days.
 */
class Pseudonymizer
{
    /** Seconds per scope kind; null means the process lifetime. */
    private const TTL = ['session' => null, 'day' => 86400, 'week' => 604800];

    private const MIN_HEX = 10;

    private const MAX_HEX = 64;

    /** The most session scopes kept in process memory; the oldest-used are dropped beyond it. */
    private const MAX_MEMORY_SESSIONS = 100;

    private static bool $persistSessions = false;

    private static int $sessionTtl = 3600;

    /** @var array<string, array{key: string, map: array<string, array{string, string}>, expires: int|null}> */
    private static array $sessions = [];

    /**
     * Session scopes in the application cache (sliding TTL) instead of process memory: needed when each request is a
     * new process (PHP-FPM, HTTP transport), so a session pseudonym issued in one call resolves in the next.
     */
    public static function usePersistentSessions(bool $on, int $ttl = 3600): void
    {
        self::$persistSessions = $on;
        self::$sessionTtl = $ttl;
    }

    /** Resets the in-memory session map (Octane, tests). Cached state is untouched. */
    public static function flushSessions(): void
    {
        self::$sessions = [];
    }

    /**
     * Returns prefix_ followed by the shortest unique hex prefix of the record's digest in the scope.
     * The same record in the same scope and by the same caller always gets the same value.
     * A day or week scope requires the caller; a session scope is already bound to its connection.
     */
    public function pseudonym(string $prefix, string $table, string $id, string $scope, ?string $caller = null): string
    {
        $key = $this->storageScope($scope, $caller);

        if (! $this->isCached($key)) {
            return $this->issue($prefix, $table, $id, $key);
        }

        $store = GateCache::store()->getStore();

        // Load-modify-save of the whole state map must not interleave, or an issued pseudonym is lost.
        if (! $store instanceof LockProvider) {
            return $this->issue($prefix, $table, $id, $key);
        }

        return $store->lock('context-gate:pseudonym:lock:'.$key, 5)->block(3, fn (): string => $this->issue($prefix, $table, $id, $key));
    }

    private function issue(string $prefix, string $table, string $id, string $key): string
    {
        $now = now()->getTimestamp();
        $state = $this->load($key, $now) ?? ['key' => $this->newKey(), 'map' => [], 'expires' => $this->expiry($key, $now)];
        $record = [$table, $id];
        $digest = $this->digest($state['key'], $table, $id);

        for ($length = self::MIN_HEX; $length <= self::MAX_HEX; $length++) {
            $candidate = $prefix.'_'.substr($digest, 0, $length);
            $owner = $state['map'][$candidate] ?? null;

            if ($owner === null || $owner === $record) {
                $state['map'][$candidate] = $record;
                $this->save($key, $state, $now);

                return $candidate;
            }
        }

        throw new RuntimeException('No free pseudonym length in this scope.');
    }

    /** @return array{table: string, id: string}|null null when the pseudonym was never issued or has expired */
    public function resolve(string $pseudonym, string $scope, ?string $caller = null): ?array
    {
        $key = $this->storageScope($scope, $caller);
        $record = $this->load($key, now()->getTimestamp())['map'][$pseudonym] ?? null;

        return $record === null ? null : ['table' => $record[0], 'id' => $record[1]];
    }

    protected function newKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    protected function digest(string $key, string $table, string $id): string
    {
        return hash_hmac('sha256', "{$table}\0{$id}", $key);
    }

    /**
     * The scope as stored: day:{caller}:{tool} or week:{caller}:{tool}. Session scopes are stored as given.
     */
    private function storageScope(string $scope, ?string $caller): string
    {
        $kind = $this->kindOf($scope);

        if ($kind === 'session') {
            return $scope;
        }

        if ($caller === null || $caller === '') {
            throw new InvalidArgumentException("Pseudonym scope [{$kind}] needs a caller.");
        }

        return "{$kind}:{$caller}:".substr($scope, strlen($kind) + 1);
    }

    /** The absolute expiry of a new state, or null for a session. */
    private function expiry(string $scope, int $now): ?int
    {
        $ttl = self::TTL[$this->kindOf($scope)];

        return $ttl === null ? ($this->isCached($scope) ? $now + self::$sessionTtl : null) : $now + $ttl;
    }

    /** @return array{key: string, map: array<string, array{string, string}>, expires: int|null}|null */
    private function load(string $scope, int $now): ?array
    {
        if (! $this->isCached($scope)) {
            return self::$sessions[$scope] ?? null;
        }

        $raw = GateCache::store()->get($this->cacheKey($scope));

        if (! is_string($raw)) {
            return null;
        }

        /** @var array{key: string, map: array<string, array{string, string}>, expires: int|null} $state */
        $state = json_decode(Crypt::decryptString($raw), true, 512, JSON_THROW_ON_ERROR);

        // A state past its absolute expiry is absent, and so is one stored without an expiry: its key and
        // pseudonyms are never used again.
        return isset($state['expires']) && $state['expires'] > $now ? $state : null;
    }

    /** @param array{key: string, map: array<string, array{string, string}>, expires: int|null} $state */
    private function save(string $scope, array $state, int $now): void
    {
        if (! $this->isCached($scope)) {
            unset(self::$sessions[$scope]);
            self::$sessions[$scope] = $state;

            if (count(self::$sessions) > self::MAX_MEMORY_SESSIONS) {
                array_shift(self::$sessions);
            }

            return;
        }

        if ($this->kindOf($scope) === 'session') {
            $state['expires'] = $now + self::$sessionTtl; // sliding: a used session stays alive
        }

        // Saving never extends the lifetime: the cache entry lives only until the first absolute expiry.
        $expires = $state['expires'] ?? throw new LogicException('A cached pseudonym state needs an expiry.');

        GateCache::store()->put(
            $this->cacheKey($scope),
            Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR)),
            $expires - $now,
        );
    }

    /** True when the scope's state lives in the application cache. */
    private function isCached(string $scope): bool
    {
        return $this->kindOf($scope) !== 'session' || self::$persistSessions;
    }

    private function kindOf(string $scope): string
    {
        $kind = explode(':', $scope, 2)[0];

        if (! array_key_exists($kind, self::TTL)) {
            throw new InvalidArgumentException("Pseudonym scope [{$kind}] is not allowed.");
        }

        return $kind;
    }

    private function cacheKey(string $scope): string
    {
        return "context-gate:pseudonym:{$scope}";
    }
}
