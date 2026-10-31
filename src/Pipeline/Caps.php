<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

/**
 * Hard limits in code. Configuration may lower a limit but never raise it above these (constitution VI).
 */
final class Caps
{
    public const PER_CALL = 65536;

    public const PER_SESSION = 2097152;

    public const PER_DAY = 20971520;

    public const PER_MINUTE = 300;

    private function __construct()
    {
    }
}
