<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fakes;

use ContextGate\Source\Fetch\HttpReply;
use ContextGate\Source\Fetch\Transport;
use ContextGate\Source\Fetch\TransportTimeout;

/** Records every request; never opens a socket. */
final class FakeTransport implements Transport
{
    /** @var list<array{url: string, address: string, timeout: float, maxBytes: int}> */
    public array $calls = [];

    public function __construct(private HttpReply|TransportTimeout $outcome)
    {
    }

    public function fetch(string $url, string $address, float $timeoutSeconds, int $maxBytes): HttpReply
    {
        $this->calls[] = ['url' => $url, 'address' => $address, 'timeout' => $timeoutSeconds, 'maxBytes' => $maxBytes];

        if ($this->outcome instanceof TransportTimeout) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}
