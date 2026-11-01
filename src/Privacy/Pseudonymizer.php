<?php

declare(strict_types=1);

namespace ContextGate\Privacy;

use ContextGate\Contracts\IssuesPseudonyms;
use ContextGate\Support\GateCache;
use Illuminate\Contracts\Cache\LockProvider;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * Keyed pseudonyms (R4, R5). A session key lives in process memory. Day and week keys live in the application
 * cache, encrypted, until their absolute expiry (FR-008, FR-009). A day or week key is bound to the caller, so
 * one caller cannot resolve another caller's pseudonym. No other scope exists, so nothing outlives seven days.
 *
 * @internal Do not extend to customise: bind your own implementation of the interface instead.
 */
class Pseudonymizer implements IssuesPseudonyms
{
    /** Seconds per scope kind; null means the process lifetime. */
    private const TTL = ['session' => null, 'day' => 86400, 'week' => 604800];

    /** A state never outlives its creation by more than seven days, however it is used. */
    private const MAX_LIFETIME = 604800;

    /** The most records one scope's map holds; beyond it the key rotates and older pseudonyms stop resolving. */
    private const MAX_MAP = 10000;

    private const MIN_HEX = 10;

    private const MAX_HEX = 64;

    /** The most session scopes kept in process memory; the oldest-used are dropped beyond it. */
    private const MAX_MEMORY_SESSIONS = 100;

    private static bool $persistSessions = false;

    private static int $sessionTtl = 3600;

    /** @var array<string, array{key: string, map: array<string, array{string, string}>, expires: int|null, created: int}> */
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
        return $this->pseudonyms($prefix, $table, [$id], $scope, $caller)[$id];
    }

    /**
     * The same as pseudonym() for many records of one table: one lock, one load and one save for the whole batch.
     *
     * @param list<string> $ids
     * @return array<string, string> id => pseudonym
     */
    public function pseudonyms(string $prefix, string $table, array $ids, string $scope, ?string $caller = null): array
    {
        $key = $this->storageScope($scope, $caller);

        if ($ids === []) {
            return [];
        }

        $store = $this->isCached($key) ? GateCache::store()->getStore() : null;

        // Load-modify-save of the whole state map must not interleave, or an issued pseudonym is lost.
        if (! $store instanceof LockProvider) {
            return $this->issue($prefix, $table, $ids, $key);
        }

        return $store->lock('context-gate:pseudonym:lock:'.$key, 5)->block(3, fn (): array => $this->issue($prefix, $table, $ids, $key));
    }

    /**
     * @param list<string> $ids
     * @return array<string, string>
     */
    private function issue(string $prefix, string $table, array $ids, string $key): array
    {
        $now = now()->getTimestamp();
        // An unreadable state counts as absent: a new key replaces it.
        $state = $this->load($key, $now) ?? $this->fresh($key, $now);
        $issued = $this->assign($state, $prefix, $table, $ids);

        if (count($state['map']) > self::MAX_MAP) {
            // Rotate: old pseudonyms stop resolving (fail closed).
            $state = $this->fresh($key, $now);
            $issued = $this->assign($state, $prefix, $table, $ids);

            if (count($state['map']) > self::MAX_MAP) {
                throw new RuntimeException('Too many records for one pseudonym scope.');
            }
        }

        $this->save($key, $state, $now);

        return $issued;
    }

    /** @return array{key: string, map: array<string, array{string, string}>, expires: int|null, created: int} */
    private function fresh(string $key, int $now): array
    {
        return ['key' => $this->newKey(), 'map' => [], 'expires' => $this->expiry($key, $now), 'created' => $now];
    }

    /**
     * @param array{key: string, map: array<string, array{string, string}>, expires: int|null, created: int} $state
     * @param list<string> $ids
     * @return array<string, string>
     */
    private function assign(array &$state, string $prefix, string $table, array $ids): array
    {
        $key = $state['key'];

        return PseudonymStates::assign($state, $prefix, $table, $ids, fn (string $id): string => $this->digest($key, $table, $id), self::MIN_HEX, self::MAX_HEX);
    }

    /** @return array{table: string, id: string}|null null when the pseudonym was never issued or has expired */
    public function resolve(string $pseudonym, string $scope, ?string $caller = null): ?array
    {
        $key = $this->storageScope($scope, $caller);
        $record = $this->load($key, now()->getTimestamp())['map'][$pseudonym] ?? null;

        return $record === null ? null : ['table' => $record[0], 'id' => $record[1]];
    }

    /** @internal Do not override outside tests: a different key source silently changes or weakens every pseudonym. */
    protected function newKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    /** @internal Do not override outside tests: a different digest silently changes or weakens every pseudonym. */
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

    /** @return array{key: string, map: array<string, array{string, string}>, expires: int|null, created: int}|null */
    private function load(string $scope, int $now): ?array
    {
        if (! $this->isCached($scope)) {
            return self::$sessions[$scope] ?? null;
        }

        return PseudonymStates::read($this->cacheKey($scope), $now);
    }

    /** @param array{key: string, map: array<string, array{string, string}>, expires: int|null, created: int} $state */
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
            // sliding: a used session stays alive, but never past created + seven days
            $state['expires'] = min($now + self::$sessionTtl, $state['created'] + self::MAX_LIFETIME);
        }

        // Saving never extends the lifetime: the cache entry lives only until the first absolute expiry.
        $expires = $state['expires'] ?? throw new LogicException('A cached pseudonym state needs an expiry.');

        PseudonymStates::write($this->cacheKey($scope), $state, $expires - $now);
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
