<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness\Checks;

use ContextGate\Classification\Catalog;
use ContextGate\Readiness\CheckStatus;
use ContextGate\Readiness\Checks\TransportCheck;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Tests\TestCase;

/** T027, spec 005 FR-013 and spec 006 FR-012: stdio is refused in production, HTTP is not. */
class TransportCheckTest extends TestCase
{
    private function context(string $path): ReadinessContext
    {
        return new ReadinessContext(['http' => ['path' => $path]], new Catalog([]), []);
    }

    private function environment(string $name): void
    {
        app()->detectEnvironment(static fn (): string => $name);
    }

    public function test_production_with_stdio_as_the_only_transport_fails_with_the_production_rule(): void
    {
        $this->environment('production');

        $result = (new TransportCheck())->run($this->context(''));

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame('context-gate.http.path', $result->subject);
        $this->assertStringContainsString('production', $result->message);
    }

    public function test_production_with_http_configured_passes(): void
    {
        $this->environment('production');

        $this->assertSame(CheckStatus::Pass, (new TransportCheck())->run($this->context('mcp/context-gate'))->status);
    }

    public function test_stdio_outside_production_passes(): void
    {
        $this->environment('local');

        $this->assertSame(CheckStatus::Pass, (new TransportCheck())->run($this->context(''))->status);
    }
}
