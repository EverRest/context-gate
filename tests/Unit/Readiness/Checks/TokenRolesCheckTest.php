<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness\Checks;

use ContextGate\Tests\Fakes\FakeRedisCache;
use ContextGate\Classification\Catalog;
use ContextGate\Readiness\CheckStatus;
use ContextGate\Readiness\Checks\TokenRolesCheck;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Token\Ceiling;
use ContextGate\Token\GateAccessToken;
use ContextGate\Token\TokenIssuer;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/** spec 010 FR-011, US3 scenario 3: an active token whose role is missing or not defined is warned, never failed. */
class TokenRolesCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        FakeRedisCache::install();
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info');
    }

    /** @param array<string, mixed> $roles */
    private function evaluate(array $roles): \ContextGate\Readiness\CheckResult
    {
        return (new TokenRolesCheck())->run(new ReadinessContext(['roles' => $roles], new Catalog([]), []));
    }

    private function issue(string $holder, string $role = 'support', ?Carbon $expiresAt = null): \Laravel\Sanctum\NewAccessToken
    {
        return TokenIssuer::issue($holder, 'Pavlo', Ceiling::Internal, $expiresAt ?? Carbon::now('UTC')->addDays(30), $role);
    }

    public function test_a_token_whose_role_is_not_defined_warns_naming_the_id_holder_and_role(): void
    {
        $new = $this->issue('Ivan');
        $id = (string) $new->accessToken->getKey();

        $result = $this->evaluate([]);

        $this->assertSame(CheckStatus::Warn, $result->status);
        $this->assertStringContainsString("token {$id} (holder Ivan) has role support, which is not defined", $result->message);
    }

    public function test_a_token_with_no_role_ability_warns_with_role_none(): void
    {
        $new = TokenFactory::create(['gate:read', 'gate:ceiling:internal'], null, 'Ivan');
        $id = (string) $new->accessToken->getKey();

        $result = $this->evaluate(['support' => ['tools' => [], 'ceiling' => 'internal']]);

        $this->assertSame(CheckStatus::Warn, $result->status);
        $this->assertStringContainsString("token {$id} (holder Ivan) has role none, which is not defined", $result->message);
    }

    public function test_the_warning_never_contains_the_token_secret(): void
    {
        $new = $this->issue('Ivan');
        $secret = explode('|', $new->plainTextToken, 2)[1];

        $result = $this->evaluate([]);

        $this->assertStringNotContainsString($secret, $result->message);
        $this->assertStringNotContainsString($secret, $result->fix);
    }

    public function test_a_token_with_a_defined_role_passes(): void
    {
        $this->issue('Ivan');

        $this->assertSame(CheckStatus::Pass, $this->evaluate(['support' => ['tools' => [], 'ceiling' => 'internal']])->status);
    }

    public function test_an_expired_token_is_not_warned(): void
    {
        $new = $this->issue('Ivan');
        // TokenIssuer refuses a past expiry, so the row is backdated the way an expired token looks at rest.
        GateAccessToken::query()->whereKey($new->accessToken->getKey())->update(['expires_at' => Carbon::now('UTC')->subDay()]);

        $this->assertSame(CheckStatus::Pass, $this->evaluate([])->status);
    }

    public function test_a_revoked_token_is_not_warned(): void
    {
        $new = $this->issue('Ivan');
        GateAccessToken::query()->whereKey($new->accessToken->getKey())->delete();

        $this->assertSame(CheckStatus::Pass, $this->evaluate([])->status);
    }

    public function test_no_tokens_passes(): void
    {
        $this->assertSame(CheckStatus::Pass, $this->evaluate([])->status);
    }

    public function test_gate_check_exits_0_and_prints_the_warning(): void
    {
        $this->issue('Ivan', 'ghost');

        $this->assertSame(0, Artisan::call('gate:check'));
        $this->assertStringContainsString('WARN roles', Artisan::output());
    }
}
