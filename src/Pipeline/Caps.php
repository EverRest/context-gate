<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

/**
 * Hard limits in code. Configuration may lower a limit but never raise it above these (constitution VI).
 *
 * @internal May change without notice.
 */
final class Caps
{
    public const PER_CALL = 65536;

    public const PER_SESSION = 2097152;

    public const PER_DAY = 20971520;

    public const PER_MINUTE = 300;

    /** Connector fetch timeout in seconds (spec 004, PinnedClient). */
    public const OPENAPI_TIMEOUT = 5.0;

    /** Largest OpenAPI document in bytes (spec 004, OpenApiParser and PinnedClient). */
    public const OPENAPI_BYTES = 2097152;

    private function __construct()
    {
    }
}
