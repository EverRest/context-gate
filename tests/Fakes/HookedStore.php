<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fakes;

use Closure;
use Illuminate\Cache\ArrayStore;

/** An array store that runs one hook right after its next increment, to place another writer inside a race window. */
final class HookedStore extends ArrayStore
{
    /** @var (Closure(self, string): mixed)|null */
    public ?Closure $afterIncrement = null;

    public function increment($key, $value = 1)
    {
        $total = parent::increment($key, $value);

        if ($this->afterIncrement !== null) {
            $hook = $this->afterIncrement;
            $this->afterIncrement = null;
            $hook($this, (string) $key);
        }

        return $total;
    }
}
