<?php

declare(strict_types=1);

namespace ContextGate\Readiness;

/** @internal May change without notice. */
interface Check
{
    public function name(): string;

    public function run(ReadinessContext $ctx): CheckResult;

    public function fixHint(): string;
}
