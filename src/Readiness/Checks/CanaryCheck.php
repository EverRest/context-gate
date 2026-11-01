<?php

declare(strict_types=1);

namespace ContextGate\Readiness\Checks;

use ContextGate\Readiness\Check;
use ContextGate\Readiness\CheckResult;
use ContextGate\Readiness\ReadinessContext;

/**
 * When canaries are set, every configured value is a non-empty string.
 *
 * @internal May change without notice.
 */
final class CanaryCheck implements Check
{
    public function name(): string
    {
        return self::class;
    }

    public function run(ReadinessContext $ctx): CheckResult
    {
        $canaries = $ctx->config['canaries'] ?? [];
        $values = (array) (is_array($canaries) ? ($canaries['values'] ?? []) : []);

        // A null entry is an unset env() and is skipped; an empty string is a value that is missing.
        $set = array_filter($values, static fn (mixed $value): bool => $value !== '' && $value !== null);

        if ($set === []) {
            $sources = $ctx->config['sources'] ?? [];
            $openapi = is_array($sources) ? ($sources['openapi'] ?? []) : [];
            $examples = is_array($openapi) ? ($openapi['examples'] ?? 'drop') : 'drop';

            if ($examples === 'redacted') {
                return CheckResult::warn('canaries.values', 'example values are returned without the canary check unless their values are listed in canaries.values', 'list the example values in canaries.values in config/context-gate.php, or set sources.openapi.examples to drop');
            }

            return CheckResult::warn('canaries', 'no canary values set; canary protection is off', $this->fixHint());
        }

        foreach ($values as $index => $value) {
            if ($value !== null && (! is_string($value) || $value === '')) {
                return CheckResult::fail("context-gate.canaries.values[{$index}]", 'must be a non-empty string', $this->fixHint());
            }
        }

        return CheckResult::pass('context-gate.canaries.values', 'every canary value is set');
    }

    public function fixHint(): string
    {
        return 'set each canary in the environment that config/context-gate.php reads, or remove the empty entry';
    }
}
