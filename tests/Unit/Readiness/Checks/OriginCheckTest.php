<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness\Checks;

use ContextGate\Classification\Catalog;
use ContextGate\Readiness\CheckStatus;
use ContextGate\Readiness\Checks\OriginCheck;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Tests\TestCase;

/** T025, spec 005 FR-014 and spec 006 FR-010: each Origin entry is an exact origin, no path. */
class OriginCheckTest extends TestCase
{
    private function run_check(string $origin): \ContextGate\Readiness\CheckResult
    {
        $ctx = new ReadinessContext(['http' => ['origins' => [$origin]]], new Catalog([]), []);

        return (new OriginCheck())->run($ctx);
    }

    public function test_an_origin_with_a_path_fails_naming_the_entry(): void
    {
        $result = $this->run_check('https://internal.example.com/path');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame('context-gate.http.origins[0]', $result->subject);
        $this->assertStringNotContainsString('internal.example.com', $result->message);
    }

    public function test_an_origin_with_a_port_passes(): void
    {
        $this->assertSame(CheckStatus::Pass, $this->run_check('https://internal.example.com:8443')->status);
    }
}
