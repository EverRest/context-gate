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

    /** Refused HTTP requests per client per minute before 429 (http.refusals_per_minute). */
    public const REFUSALS_PER_MINUTE = 600;

    /** Connector fetch timeout in seconds. */
    public const OPENAPI_TIMEOUT = 5.0;

    /** Largest OpenAPI document in bytes. */
    public const OPENAPI_BYTES = 2097152;

    /** Most steps in one runbook. */
    public const RUNBOOK_STEPS = 20;

    /** Longest step timeout in seconds. */
    public const RUNBOOK_STEP_TIMEOUT = 5.0;

    /** Largest step output in bytes. */
    public const RUNBOOK_STEP_BYTES = 2048;

    /** Largest report in bytes, all rows together. */
    public const RUNBOOK_REPORT_BYTES = 8192;

    /** Longest runbook run in seconds, all steps together. */
    public const RUNBOOK_RUN_SECONDS = 30.0;

    private function __construct()
    {
    }
}
