<?php

declare(strict_types=1);

namespace ContextGate\Readiness\Checks;

use ContextGate\Contracts\Runbook;
use ContextGate\Readiness\Check;
use ContextGate\Readiness\CheckResult;
use ContextGate\Readiness\CheckStatus;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Runbook\RunbookFingerprint;
use ContextGate\Runbook\RunbookRegistry;
use ContextGate\Runbook\RunbookValidator;

/**
 * spec 018 FR-021, US5: every registered runbook is valid (class, steps, names, read-only, caps) and its tool's approved
 * fingerprint matches. results() gives one line per runbook; run() is the single worst line, for the registry contract.
 *
 * @internal May change without notice.
 */
final class RunbookCheck implements Check
{
    public function name(): string
    {
        return self::class;
    }

    /** @return list<CheckResult> one result per registered runbook; the fingerprint is checked for a valid runbook with a tool entry */
    public function results(ReadinessContext $ctx): array
    {
        $map = (array) ($ctx->config['runbooks'] ?? []);
        $tools = (array) ($ctx->config['tools'] ?? []);
        $problems = [];

        foreach ((new RunbookRegistry($map))->problems() as $problem) {
            $problems[$problem['key']][] = $problem['message'];
        }

        $results = [];

        foreach ($map as $key => $class) {
            $key = (string) $key;

            if (is_string($class) && class_exists($class)) {
                foreach (RunbookValidator::violations($key, $class) as $violation) {
                    $problems[$key][] = $violation['message'];
                }
            }

            if (($problems[$key] ?? []) !== []) {
                $results[] = CheckResult::fail("runbooks.{$key}", implode('; ', array_unique($problems[$key])), $this->fixHint());

                continue;
            }

            $results[] = CheckResult::pass("runbooks.{$key}", 'the runbook is valid');

            $tool = $tools["runbook.{$key}"] ?? null;

            if (is_array($tool) && is_string($class) && is_a($class, Runbook::class, true)) {
                $results[] = ($tool['fingerprint'] ?? null) === RunbookFingerprint::of(app($class))
                    ? CheckResult::pass("runbook.{$key}", 'the approved fingerprint matches')
                    : CheckResult::fail("runbook.{$key}", 'the fingerprint differs from the approved value', "run gate:fingerprint runbook.{$key} and paste the value into tools.runbook.{$key}.fingerprint");
            }
        }

        return $results;
    }

    public function run(ReadinessContext $ctx): CheckResult
    {
        $results = $this->results($ctx);

        foreach ($results as $result) {
            if ($result->status === CheckStatus::Fail) {
                return $result;
            }
        }

        return CheckResult::pass('runbooks', $results === [] ? 'no runbooks are registered' : 'every runbook is valid');
    }

    public function fixHint(): string
    {
        return 'fix the runbook named above in its class or in config runbooks, then run gate:check again';
    }
}
