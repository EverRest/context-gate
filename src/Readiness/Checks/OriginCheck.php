<?php

declare(strict_types=1);

namespace ContextGate\Readiness\Checks;

use ContextGate\Readiness\Check;
use ContextGate\Readiness\CheckResult;
use ContextGate\Readiness\ReadinessContext;

/**
 * spec 005 FR-014, spec 006 FR-010: each Origin entry is an exact origin: scheme, host, optional port, no path.
 *
 * @internal May change without notice.
 */
final class OriginCheck implements Check
{
    public function name(): string
    {
        return self::class;
    }

    public function run(ReadinessContext $ctx): CheckResult
    {
        $http = $ctx->config['http'] ?? [];

        foreach ((array) (is_array($http) ? ($http['origins'] ?? []) : []) as $index => $origin) {
            if (! is_string($origin) || ! $this->isExactOrigin($origin)) {
                return CheckResult::fail("context-gate.http.origins[{$index}]", 'is not an exact origin (scheme, host and optional port only)', $this->fixHint());
            }
        }

        return CheckResult::pass('context-gate.http.origins', 'every entry is an exact origin');
    }

    private function isExactOrigin(string $origin): bool
    {
        $parts = parse_url($origin);

        return is_array($parts)
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && isset($parts['host'])
            && array_intersect_key($parts, array_flip(['path', 'query', 'fragment', 'user', 'pass'])) === [];
    }

    public function fixHint(): string
    {
        return 'use the bare origin (https://host or https://host:port) in context-gate.http.origins';
    }
}
