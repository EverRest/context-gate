<?php

declare(strict_types=1);

namespace ContextGate\Readiness\Checks;

use ContextGate\Config\CapsValidator;
use ContextGate\Readiness\Check;
use ContextGate\Readiness\CheckResult;
use ContextGate\Readiness\ReadinessContext;

/** spec 002 FR-022: every configured limit is at most its code cap. */
final class CapsCheck implements Check
{
    public function name(): string
    {
        return self::class;
    }

    public function run(ReadinessContext $ctx): CheckResult
    {
        $firewall = $ctx->config['firewall'] ?? [];
        $limits = [];

        foreach ((array) (is_array($firewall) ? ($firewall['limits'] ?? []) : []) as $key => $value) {
            if (is_int($value)) {
                $limits["firewall.limits.{$key}"] = $value;
            }
        }

        foreach (CapsValidator::violations($limits) as $key) {
            return CheckResult::fail("context-gate.{$key}", 'is above its code cap', $this->fixHint());
        }

        return CheckResult::pass('context-gate.firewall.limits', 'every limit is within its code cap');
    }

    public function fixHint(): string
    {
        return 'lower the value in config/context-gate.php to at most the code cap, or set it to null';
    }
}
