<?php

declare(strict_types=1);

namespace ContextGate\Readiness\Checks;

use ContextGate\Readiness\Check;
use ContextGate\Readiness\CheckResult;
use ContextGate\Readiness\ReadinessContext;

/**
 * spec 004 FR-005: each URL allowlist entry is a bare, lowercase host name: no scheme, no path.
 *
 * @internal May change without notice.
 */
final class UrlAllowlistCheck implements Check
{
    public function name(): string
    {
        return self::class;
    }

    public function run(ReadinessContext $ctx): CheckResult
    {
        $sources = $ctx->config['sources'] ?? [];
        $openapi = is_array($sources) ? ($sources['openapi'] ?? []) : [];
        $allowlist = is_array($openapi) ? ($openapi['allowlist'] ?? []) : [];

        foreach ((array) $allowlist as $index => $host) {
            if (! is_string($host) || $host === '' || $host !== strtolower($host) || preg_match('#[:/@]#', $host) === 1) {
                return CheckResult::fail("context-gate.sources.openapi.allowlist[{$index}]", 'is not a bare lowercase host name (no scheme, no path)', $this->fixHint());
            }
        }

        return CheckResult::pass('context-gate.sources.openapi.allowlist', 'every entry is a bare lowercase host name');
    }

    public function fixHint(): string
    {
        return 'list hosts only, lowercase, without https:// or a path, in context-gate.sources.openapi.allowlist';
    }
}
