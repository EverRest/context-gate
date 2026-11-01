<?php

declare(strict_types=1);

namespace ContextGate\Readiness\Checks;

use ContextGate\Readiness\Check;
use ContextGate\Readiness\CheckResult;
use ContextGate\Readiness\ReadinessContext;

/**
 * spec 005 FR-013, spec 006 FR-012: in production stdio is refused, so HTTP must be configured.
 *
 * @internal May change without notice.
 */
final class TransportCheck implements Check
{
    public function name(): string
    {
        return self::class;
    }

    public function run(ReadinessContext $ctx): CheckResult
    {
        $http = $ctx->config['http'] ?? [];
        $path = is_array($http) ? ($http['path'] ?? null) : null;
        $httpConfigured = is_string($path) && $path !== '';

        if (app()->environment('production') && ! $httpConfigured) {
            return CheckResult::fail(
                'context-gate.http.path',
                'the application runs in production and stdio is the only transport; stdio is refused in production',
                $this->fixHint(),
            );
        }

        return CheckResult::pass('context-gate.http.path', 'a transport suitable for this environment is configured');
    }

    public function fixHint(): string
    {
        return 'set context-gate.http.path to the MCP route, so that authenticated HTTP serves the gate in production';
    }
}
