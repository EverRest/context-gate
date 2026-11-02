<?php

declare(strict_types=1);

namespace ContextGate\Privacy;

use Closure;
use ContextGate\Support\GateCache;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * The storage and map mechanics behind Pseudonymizer: reading and writing the encrypted cached state, and
 * giving each record the shortest free digest prefix.
 *
 * @phpstan-type State array{key: string, map: array<string, array{string, string}>, expires: int|null, created: int}
 * @internal
 */
final class PseudonymStates
{
    /**
     * @return State|null null for an absent, expired or unreadable state (rotated APP_KEY, corrupted value)
     */
    public static function read(string $cacheKey, int $now): ?array
    {
        $raw = GateCache::guard(fn (): mixed => GateCache::store()->get($cacheKey));

        if (! is_string($raw)) {
            return null;
        }

        try {
            $state = json_decode(Crypt::decryptString($raw), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (! is_array($state) || ! is_string($state['key'] ?? null) || ! is_array($state['map'] ?? null)) {
            return null;
        }

        $state['created'] = is_int($state['created'] ?? null) ? $state['created'] : $now;

        /** @var State $state */
        // A state past its absolute expiry is absent, and so is one stored without an expiry.
        return isset($state['expires']) && $state['expires'] > $now ? $state : null;
    }

    /** @param State $state */
    public static function write(string $cacheKey, array $state, int $ttl): void
    {
        $value = Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR));
        $stored = GateCache::guard(fn (): bool => GateCache::store()->put($cacheKey, $value, $ttl));

        // A value over the store's size limit is refused: fail closed instead of issuing what cannot resolve.
        if (! $stored) {
            throw new RuntimeException('The pseudonym state could not be stored.');
        }
    }

    /**
     * Runs $fn under the scope's lock. A lock that cannot be taken in time, or a store that fails while locking, is
     * StoreUnavailable. A refusal thrown by $fn is rethrown after the lock is released, so it keeps its own reason.
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public static function locked(LockProvider $store, string $name, Closure $fn): mixed
    {
        $outcome = GateCache::guard(fn (): array => $store->lock($name, 5)->block(3, function () use ($fn): array {
            try {
                return ['value' => $fn()];
            } catch (Throwable $refusal) {
                return ['refusal' => $refusal];
            }
        }));

        if (isset($outcome['refusal'])) {
            throw $outcome['refusal'];
        }

        return $outcome['value'];
    }

    /**
     * @param State $state
     * @param list<string> $ids
     * @param Closure(string): string $digest hex digest of a record id
     * @return array<string, string> id => pseudonym
     */
    public static function assign(array &$state, string $prefix, string $table, array $ids, Closure $digest, int $minHex, int $maxHex): array
    {
        $issued = [];

        foreach ($ids as $id) {
            $record = [$table, $id];
            $hex = $digest($id);

            for ($length = $minHex; $length <= $maxHex; $length++) {
                $candidate = $prefix.'_'.substr($hex, 0, $length);
                $owner = $state['map'][$candidate] ?? null;

                if ($owner === null || $owner === $record) {
                    $state['map'][$candidate] = $record;
                    $issued[$id] = $candidate;

                    continue 2;
                }
            }

            throw new RuntimeException('No free pseudonym length in this scope.');
        }

        return $issued;
    }
}
