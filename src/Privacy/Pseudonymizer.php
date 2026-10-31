<?php

declare(strict_types=1);

namespace ContextGate\Privacy;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use RuntimeException;

/**
 * Keyed pseudonyms (R4, R5). A session key lives in process memory. Day and week keys live in the application
 * cache, encrypted, for exactly their TTL (FR-008, FR-009). No other scope exists, so nothing outlives seven days.
 */
class Pseudonymizer
{
    /** Seconds per scope kind; null means the process lifetime. */
    private const TTL = ['session' => null, 'day' => 86400, 'week' => 604800];

    private const MIN_HEX = 10;

    private const MAX_HEX = 64;

    /** @var array<string, array{key: string, map: array<string, array{string, string}>}> */
    private static array $sessions = [];

    /**
     * Returns prefix_ followed by the shortest unique hex prefix of the record's digest in the scope.
     * The same record in the same scope always gets the same value.
     */
    public function pseudonym(string $prefix, string $table, string $id, string $scope): string
    {
        $state = $this->load($scope) ?? ['key' => $this->newKey(), 'map' => []];
        $record = [$table, $id];
        $digest = $this->digest($state['key'], $table, $id);

        for ($length = self::MIN_HEX; $length <= self::MAX_HEX; $length++) {
            $candidate = $prefix.'_'.substr($digest, 0, $length);
            $owner = $state['map'][$candidate] ?? null;

            if ($owner === null || $owner === $record) {
                $state['map'][$candidate] = $record;
                $this->save($scope, $state);

                return $candidate;
            }
        }

        throw new RuntimeException('No free pseudonym length in this scope.');
    }

    /** @return array{table: string, id: string}|null null when the pseudonym was never issued or has expired */
    public function resolve(string $pseudonym, string $scope): ?array
    {
        $record = $this->load($scope)['map'][$pseudonym] ?? null;

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

    /** @return array{key: string, map: array<string, array{string, string}>}|null */
    private function load(string $scope): ?array
    {
        if ($this->kindOf($scope) === 'session') {
            return self::$sessions[$scope] ?? null;
        }

        $raw = Cache::get($this->cacheKey($scope));

        if (! is_string($raw)) {
            return null;
        }

        /** @var array{key: string, map: array<string, array{string, string}>} $state */
        $state = json_decode(Crypt::decryptString($raw), true, 512, JSON_THROW_ON_ERROR);

        return $state;
    }

    /** @param array{key: string, map: array<string, array{string, string}>} $state */
    private function save(string $scope, array $state): void
    {
        $kind = $this->kindOf($scope);

        if ($kind === 'session') {
            self::$sessions[$scope] = $state;

            return;
        }

        Cache::put(
            $this->cacheKey($scope),
            Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR)),
            self::TTL[$kind],
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
