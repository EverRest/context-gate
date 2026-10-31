<?php

declare(strict_types=1);

namespace ContextGate\Privacy;

use Illuminate\Support\Facades\Cache;
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

    /** @var array<string, array{key: string, map: array<string, array{string, string}>, expires: int|null}> */
    private static array $sessions = [];

    /**
     * Returns prefix_ followed by the shortest unique hex prefix of the record's digest in the scope.
     * The same record in the same scope and by the same caller always gets the same value.
     * A day or week scope requires the caller; a session scope is already bound to its connection.
     */
    public function pseudonym(string $prefix, string $table, string $id, string $scope, ?string $caller = null): string
    {
        $now = now()->getTimestamp();
        $key = $this->storageScope($scope, $caller);
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

        return $ttl === null ? null : $now + $ttl;
    }

    /** @return array{key: string, map: array<string, array{string, string}>, expires: int|null}|null */
    private function load(string $scope, int $now): ?array
    {
        if ($this->kindOf($scope) === 'session') {
            return self::$sessions[$scope] ?? null;
        }

        $raw = Cache::get($this->cacheKey($scope));

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
        if ($this->kindOf($scope) === 'session') {
            self::$sessions[$scope] = $state;

            return;
        }

        // Saving never extends the lifetime: the cache entry lives only until the first absolute expiry.
        $expires = $state['expires'] ?? throw new LogicException('A cached pseudonym state needs an expiry.');

        Cache::put(
            $this->cacheKey($scope),
            Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR)),
            $expires - $now,
        );
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
