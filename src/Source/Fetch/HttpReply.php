<?php

declare(strict_types=1);

namespace ContextGate\Source\Fetch;

/** One response from a Transport. The body is cut at the cap the transport was given. */
final readonly class HttpReply
{
    public function __construct(public int $status, public string $contentType, public string $body)
    {
    }
}
