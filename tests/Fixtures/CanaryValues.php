<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

/**
 * Canary values for tests. A response or argument containing one of them must be blocked.
 */
final class CanaryValues
{
    public const EMAIL = 'canary-7f3a@example.invalid';

    public const TOKEN = 'CANARY_TOKEN_0c9e1a';

    private function __construct()
    {
    }
}
