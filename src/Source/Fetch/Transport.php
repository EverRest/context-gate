<?php

declare(strict_types=1);

namespace ContextGate\Source\Fetch;

interface Transport
{
    /** Connects to $address for the host in $url; the caller has already checked that address. */
    public function fetch(string $url, string $address, float $timeoutSeconds): HttpReply;
}
