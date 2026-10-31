<?php

declare(strict_types=1);

namespace ContextGate\Source;

final readonly class SourceRequest
{
    /** @param array<string, mixed> $arguments */
    public function __construct(public array $arguments)
    {
    }
}
