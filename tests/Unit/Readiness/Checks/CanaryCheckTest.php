<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness\Checks;

use ContextGate\Classification\Catalog;
use ContextGate\Readiness\CheckStatus;
use ContextGate\Readiness\Checks\CanaryCheck;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Tests\TestCase;

/** T023, spec 002 FR-017 and spec 006 FR-008: canaries set but one empty value fails and names the key. */
class CanaryCheckTest extends TestCase
{
    /** @param array<array-key, mixed> $values */
    private function context(array $values): ReadinessContext
    {
        return new ReadinessContext(['canaries' => ['values' => $values]], new Catalog([]), []);
    }

    public function test_an_empty_canary_next_to_a_real_one_fails_naming_the_key(): void
    {
        $result = (new CanaryCheck())->run($this->context(['', 'FAKE_FIXTURE_ONLY_canary']));

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame('context-gate.canaries.values[0]', $result->subject);
    }

    public function test_no_canary_values_warns_that_protection_is_off(): void
    {
        $result = (new CanaryCheck())->run($this->context(['']));

        $this->assertSame(CheckStatus::Warn, $result->status);
        $this->assertSame('canaries', $result->subject);
    }

    public function test_set_canary_values_pass(): void
    {
        $this->assertSame(CheckStatus::Pass, (new CanaryCheck())->run($this->context(['FAKE_FIXTURE_ONLY_canary']))->status);
    }
}
