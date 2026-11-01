<?php

declare(strict_types=1);

namespace ContextGate\Settings;

use Closure;
use RuntimeException;

/**
 * spec 007 F5: the index read-modify-write runs under an exclusive file lock in the config directory, so two
 * processes applying at once cannot lose an entry or orphan a version file. The lock is a file, not a cache lock.
 *
 * @internal May change without notice.
 */
final class ConfigLock
{
    /**
     * @template T
     * @param Closure(): T $fn
     * @param ?Closure(resource): bool $acquire test seam: replaces the flock() call so a failed lock can be simulated
     * @return T
     */
    public static function run(string $dir, Closure $fn, ?Closure $acquire = null): mixed
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $handle = fopen($dir.'/.lock', 'c');

        if ($handle === false) {
            throw new RuntimeException('The configuration lock could not be opened; nothing was applied.');
        }

        try {
            $acquire ??= static fn ($h): bool => flock($h, LOCK_EX);

            if (! $acquire($handle)) {
                throw new RuntimeException('The configuration lock could not be taken on '.$dir.'/.lock; nothing was applied.');
            }

            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
