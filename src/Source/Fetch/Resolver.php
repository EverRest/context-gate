<?php

declare(strict_types=1);

namespace ContextGate\Source\Fetch;

/** Looks a host up once; the answer is checked and then used as the connect address. */
interface Resolver
{
    /** @return list<string> */
    public function addresses(string $host): array;
}
