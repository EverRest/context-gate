<?php

declare(strict_types=1);

namespace ContextGate\Source\Fetch;

/** One response from a Transport. Chunks are read lazily so the size cap can stop the read (FR-010). */
final readonly class HttpReply
{
    /** @param iterable<string> $chunks */
    public function __construct(public int $status, public string $contentType, public iterable $chunks)
    {
    }
}
