<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fakes;

use Illuminate\Cache\ArrayStore;
use RuntimeException;

/**
 * An array store that fails like an unreachable Redis while $down is set: only on keys containing $only (every key
 * when null), and only for the operations in $methods (every operation when null). The message carries fake
 * connection details, which must never reach a receipt or a response.
 */
final class FlakyStore extends ArrayStore
{
    public static bool $down = false;

    public static ?string $only = null;

    /** @var list<string>|null */
    public static ?array $methods = null;

    public static function reset(): void
    {
        self::$down = false;
        self::$only = null;
        self::$methods = null;
    }

    private function check(string $method, string $key): void
    {
        if (! self::$down) {
            return;
        }

        if (self::$only !== null && ! str_contains($key, self::$only)) {
            return;
        }

        if (self::$methods !== null && ! in_array($method, self::$methods, true)) {
            return;
        }

        throw new RuntimeException('connect tcp://10.0.0.1:6379 password=FAKE_FIXTURE_ONLY');
    }

    public function get($key)
    {
        $this->check('get', $key);

        return parent::get($key);
    }

    public function put($key, $value, $seconds)
    {
        $this->check('put', $key);

        return parent::put($key, $value, $seconds);
    }

    public function increment($key, $value = 1)
    {
        $this->check('increment', $key);

        return parent::increment($key, $value);
    }

    public function decrement($key, $value = 1)
    {
        $this->check('decrement', $key);

        return parent::decrement($key, $value);
    }

    public function forget($key)
    {
        $this->check('forget', $key);

        return parent::forget($key);
    }
}
