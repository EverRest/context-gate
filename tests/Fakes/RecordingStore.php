<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fakes;

use Illuminate\Cache\ArrayStore;

/** An array store that remembers every key any operation touched, lock names included (ArrayLock keeps them apart). */
final class RecordingStore extends ArrayStore
{
    /** @var list<string> */
    public array $touched = [];

    private function touch(string $key): void
    {
        $this->touched[] = $key;
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        $this->touch((string) $name);

        return parent::lock($name, $seconds, $owner);
    }

    public function get($key)
    {
        $this->touch($key);

        return parent::get($key);
    }

    public function put($key, $value, $seconds)
    {
        $this->touch($key);

        return parent::put($key, $value, $seconds);
    }

    public function increment($key, $value = 1)
    {
        $this->touch($key);

        return parent::increment($key, $value);
    }

    public function decrement($key, $value = 1)
    {
        $this->touch($key);

        return parent::decrement($key, $value);
    }

    public function forget($key)
    {
        $this->touch($key);

        return parent::forget($key);
    }
}
