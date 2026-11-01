<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness\Checks;

use ContextGate\Classification\Catalog;
use ContextGate\Readiness\CheckStatus;
use ContextGate\Readiness\Checks\AbilitySetCheck;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;

/** T029, spec 005 FR-004 and spec 006 FR-011: an existing token carries only package abilities; no value is shown. */
class AbilitySetCheckTest extends TestCase
{
    private function context(): ReadinessContext
    {
        return new ReadinessContext([], new Catalog([]), []);
    }

    public function test_a_token_with_an_ability_outside_the_package_set_fails_naming_the_id_and_ability(): void
    {
        TokenFactory::migrate();
        $token = TokenFactory::create(['gate:read', 'gate:admin']);

        $result = (new AbilitySetCheck())->run($this->context());

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertStringContainsString((string) $token->accessToken->getKey(), $result->message);
        $this->assertStringContainsString('gate:admin', $result->message);
        $this->assertStringNotContainsString($token->plainTextToken, $result->message);
    }

    public function test_tokens_with_package_abilities_pass(): void
    {
        TokenFactory::migrate();
        TokenFactory::create(['gate:read', 'gate:ceiling:internal']);

        $this->assertSame(CheckStatus::Pass, (new AbilitySetCheck())->run($this->context())->status);
    }
}
