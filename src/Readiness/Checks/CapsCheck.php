<?php

declare(strict_types=1);

namespace ContextGate\Readiness\Checks;

use ContextGate\Config\CapsValidator;
use ContextGate\Readiness\Check;
use ContextGate\Readiness\CheckResult;
use ContextGate\Readiness\ReadinessContext;
use Illuminate\Support\Arr;

/**
 * spec 002 FR-022 / spec 007 FR-011: every configured limit is at most its code cap, all named in one result.
 *
 * @internal May change without notice.
 */
final class CapsCheck implements Check
{
    public function name(): string
    {
        return self::class;
    }

    public function run(ReadinessContext $ctx): CheckResult
    {
        $configured = [];

        foreach (array_keys(CapsValidator::caps()) as $key) {
            $value = Arr::get($ctx->config, $key);

            if (is_int($value) || is_float($value)) {
                $configured[$key] = $value;
            }
        }

        $violations = CapsValidator::violations($configured);

        if ($violations !== []) {
            return CheckResult::fail(
                "context-gate.{$violations[0]}",
                implode(', ', $violations).': above its code cap',
                $this->fixHint(),
            );
        }

        return CheckResult::pass('context-gate.firewall.limits', 'every limit is within its code cap');
    }

    public function fixHint(): string
    {
        return 'lower the value in config/context-gate.php to at most the code cap, or set it to null';
    }
}
