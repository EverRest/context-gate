<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness\Checks;

use ContextGate\Classification\Catalog;
use ContextGate\Readiness\CheckResult;
use ContextGate\Readiness\CheckStatus;
use ContextGate\Readiness\Checks\SettingsCheck;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Tests\TestCase;

/** spec 007 T019 (US1): a weaker profile in production fails and names the profile key. */
class SettingsCheckTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function checkWith(array $config, string $env): CheckResult
    {
        config(['app.env' => $env]);

        return (new SettingsCheck())->run(new ReadinessContext($config, new Catalog([]), []));
    }

    public function test_balanced_in_production_fails_and_names_the_profile(): void
    {
        $result = $this->checkWith(['profile' => 'balanced'], 'production');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame('context-gate.profile', $result->subject);
        $this->assertStringContainsString('profile', $result->fix);
    }

    public function test_no_profile_in_production_passes(): void
    {
        $this->assertSame(CheckStatus::Pass, $this->checkWith([], 'production')->status);
    }

    public function test_no_profile_in_development_passes(): void
    {
        $this->assertSame(CheckStatus::Pass, $this->checkWith([], 'development')->status);
    }
}
