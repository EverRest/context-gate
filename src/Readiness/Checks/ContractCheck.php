<?php

declare(strict_types=1);

namespace ContextGate\Readiness\Checks;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\ContractValidator;
use ContextGate\Readiness\Check;
use ContextGate\Readiness\CheckResult;
use ContextGate\Readiness\ReadinessContext;
use InvalidArgumentException;

/** spec 002 FR-004, FR-007, FR-011: every enabled tool has a valid contract; no secret field; relations go one level. */
final class ContractCheck implements Check
{
    public function name(): string
    {
        return self::class;
    }

    public function run(ReadinessContext $ctx): CheckResult
    {
        foreach ((array) ($ctx->config['tools'] ?? []) as $name => $entry) {
            $contract = is_array($entry) ? ($entry['contract'] ?? null) : null;

            if ($contract === null) {
                if (is_array($entry) && ($entry['enabled'] ?? false) === true) {
                    return CheckResult::fail("tools.{$name}", 'enabled tool has no contract', $this->fixHint());
                }

                continue;
            }

            if (! is_string($contract) || ! is_a($contract, ContextContract::class, true)) {
                return CheckResult::fail("tools.{$name}.contract", 'is not a contract class', $this->fixHint());
            }

            try {
                (new ContractValidator())->validate(new $contract(), $ctx->catalog);
            } catch (InvalidArgumentException $e) {
                return CheckResult::fail("tools.{$name}.contract", $e->getMessage(), $this->fixHint());
            }
        }

        return CheckResult::pass('tools', 'every contract loads under the rules');
    }

    public function fixHint(): string
    {
        return 'fix the contract class or its field list in the tool entry of config/context-gate.php';
    }
}
