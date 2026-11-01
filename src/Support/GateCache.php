<?php

declare(strict_types=1);

namespace ContextGate\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The one place the gate picks its cache store: context-gate.cache_store, or the host default when null.
 *
 * @internal May change without notice.
 */
final class GateCache
{
    /** @return Repository the facade is mocked in tests, so the type is documented, not enforced */
    public static function store()
    {
        $name = config('context-gate.cache_store');

        return Cache::store(is_string($name) ? $name : null);
    }
}
