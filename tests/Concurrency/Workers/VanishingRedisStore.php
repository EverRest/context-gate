<?php

declare(strict_types=1);

namespace ContextGate\Tests\Concurrency\Workers;

use Illuminate\Cache\RedisStore;

/** A Redis store whose counter (the first key containing the needle) disappears once, between the add and the increment. */
final class VanishingRedisStore extends RedisStore
{
    private bool $vanished = false;

    public string $needle = 'budget:day:';

    public function increment($key, $value = 1)
    {
        if (! $this->vanished && str_contains((string) $key, $this->needle)) {
            $this->vanished = true;
            $this->forget($key);
        }

        return parent::increment($key, $value);
    }
}
