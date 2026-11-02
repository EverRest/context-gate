<?php

declare(strict_types=1);

namespace ContextGate\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The one place the gate picks its cache store (context-gate.cache_store, or the host default when null), and the
 * one place its key namespace is written.
 *
 * @internal May change without notice.
 */
final class GateCache
{
    public const NAMESPACE = 'context-gate:';

    /** The configured store name, or null for the host default. */
    public static function name(): ?string
    {
        $name = config('context-gate.cache_store');

        return is_string($name) ? $name : null;
    }

    /** @return Repository the facade is mocked in tests, so the type is documented, not enforced */
    public static function store()
    {
        // A name the host does not define is a store failure too: every call is refused, none is served.
        return self::guard(fn () => Cache::store(self::name()));
    }

    /**
     * Runs a store call. Any failure, including a lock that timed out, becomes StoreUnavailable: the caller refuses.
     *
     * @template T
     *
     * @param  \Closure(): T  $fn
     * @return T
     *
     * @throws StoreUnavailable
     */
    public static function guard(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (StoreUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new StoreUnavailable($e);
        }
    }

    public static function key(string $suffix): string
    {
        return self::NAMESPACE.$suffix;
    }

    public static function lockKey(string $suffix): string
    {
        return self::NAMESPACE.'lock:'.$suffix;
    }
}
