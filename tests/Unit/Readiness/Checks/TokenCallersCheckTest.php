<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness\Checks;

use ContextGate\Tests\Fakes\FakeRedisCache;
use ContextGate\Classification\Catalog;
use ContextGate\Readiness\CheckStatus;
use ContextGate\Readiness\Checks\TokenCallersCheck;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Token\Ceiling;
use ContextGate\Token\GateAccessToken;
use ContextGate\Token\TokenIssuer;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/** An issued, non-revoked, non-expired token needs a callers entry under token:<id>; the check warns, never fails. */
class TokenCallersCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        FakeRedisCache::install();
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info');
    }

    /** @param array<string, mixed> $callers */
    private function evaluate(array $callers): \ContextGate\Readiness\CheckResult
    {
        return (new TokenCallersCheck())->run(new ReadinessContext(['callers' => $callers], new Catalog([]), []));
    }

    private function issue(string $holder, ?Carbon $expiresAt = null): \Laravel\Sanctum\NewAccessToken
    {
        return TokenIssuer::issue($holder, 'Pavlo', Ceiling::Internal, $expiresAt ?? Carbon::now('UTC')->addDays(30));
    }

    public function test_a_token_without_a_callers_entry_warns_and_names_the_key_and_holder(): void
    {
        $new = $this->issue('Ivan');
        $key = 'token:'.$new->accessToken->getKey();

        $result = $this->evaluate([]);

        $this->assertSame(CheckStatus::Warn, $result->status);
        $this->assertStringContainsString($key, $result->message);
        $this->assertStringContainsString('Ivan', $result->message);
    }

    public function test_the_warning_never_contains_the_token_secret(): void
    {
        $new = $this->issue('Ivan');
        $secret = explode('|', $new->plainTextToken, 2)[1];

        $result = $this->evaluate([]);

        $this->assertStringNotContainsString($secret, $result->message);
        $this->assertStringNotContainsString($secret, $result->fix);
    }

    public function test_a_token_with_a_callers_entry_passes(): void
    {
        $new = $this->issue('Ivan');

        $result = $this->evaluate(['token:'.$new->accessToken->getKey() => ['tools' => []]]);

        $this->assertSame(CheckStatus::Pass, $result->status);
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
        $this->issue('Ivan');

        $this->assertSame(0, Artisan::call('gate:check'));
        $this->assertStringContainsString('WARN callers', Artisan::output());
    }
}
