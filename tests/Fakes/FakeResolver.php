<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fakes;

use ContextGate\Source\Fetch\Resolver;

/** Answers the nth lookup with the nth scripted list; the last list repeats. */
final class FakeResolver implements Resolver
{
    public int $lookups = 0;

    /** @param list<list<string>> $answers */
    public function __construct(private array $answers)
    {
    }

    public function addresses(string $host): array
    {
        $this->lookups++;

        return $this->answers[min($this->lookups, count($this->answers)) - 1];
    }
}
