<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness\Checks;

use ContextGate\Classification\Catalog;
use ContextGate\Readiness\CheckStatus;
use ContextGate\Readiness\Checks\TokenTableCheck;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

/** T026, spec 005 FR-011: the token tables exist; an empty token table is a valid state. */
class TokenTableCheckTest extends TestCase
{
    private function context(): ReadinessContext
    {
        return new ReadinessContext([], new Catalog([]), []);
    }

    public function test_a_missing_issuer_table_fails_naming_it(): void
    {
        TokenFactory::migrate();
        Schema::drop('gate_token_issuers');

        $result = (new TokenTableCheck())->run($this->context());

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame('gate_token_issuers', $result->subject);
    }

    public function test_an_empty_token_table_passes_with_a_note(): void
    {
        TokenFactory::migrate();

        $result = (new TokenTableCheck())->run($this->context());

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertStringContainsString('no tokens', $result->message);
    }
}
