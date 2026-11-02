<?php

declare(strict_types=1);

namespace ContextGate\Tests\Concurrency\Workers;

use Illuminate\Cache\RedisStore;

/** A Redis store whose day counter disappears once, between the add and the increment (an expiry at that instant). */
final class VanishingRedisStore extends RedisStore
{
    private bool $vanished = false;

    public function increment($key, $value = 1)
    {
        if (! $this->vanished && str_contains((string) $key, 'budget:day:')) {
            $this->vanished = true;
            $this->forget($key);
        }

        return parent::increment($key, $value);
    }
}
