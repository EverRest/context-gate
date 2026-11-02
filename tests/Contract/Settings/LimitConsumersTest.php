<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Http\RefusalLedger;
use ContextGate\Pipeline\Budget;
use ContextGate\Source\Fetch\HttpReply;
use ContextGate\Server\Sources\OpenApiEndpointsTool;
use ContextGate\Tests\Fakes\FakeResolver;
use ContextGate\Tests\Fakes\FakeTransport;
use ContextGate\Tests\TestCase;
use Illuminate\Cache\RateLimiter;

/**
 * spec 009 T002 (characterization, US2 scenario 1 baseline): under strict and balanced, the limit consumers use the
 * profile values. The docs.read consumer is covered by DocsReadEffectiveLimitTest (same profile values).
 */
class LimitConsumersTest extends TestCase
{
    private const URL = 'https://api.example.test/openapi.json';

    /** @return array{perCall: int, perMinute: int} the limits Budget holds */
    private function budgetUnder(string $profile): array
    {
        config(['context-gate.profile' => $profile, 'app.env' => 'local']);
        $store = app('cache')->store('array');
        $budget = new Budget($store, new RateLimiter($store), 'alice', 'alice', 3600);

        $perCall = new \ReflectionProperty(Budget::class, 'perCall');
        $perMinute = new \ReflectionProperty(Budget::class, 'perMinute');

        return ['perCall' => (int) $perCall->getValue($budget), 'perMinute' => (int) $perMinute->getValue($budget)];
    }

    public function test_budget_uses_the_strict_values_by_default(): void
    {
        $this->assertSame(['perCall' => 16384, 'perMinute' => 60], $this->budgetUnder('strict'));
    }

    public function test_budget_uses_the_code_caps_under_balanced(): void
    {
        $this->assertSame(['perCall' => 65536, 'perMinute' => 300], $this->budgetUnder('balanced'));
    }

    public function test_refusal_ledger_uses_the_strict_refusal_limit(): void
    {
        config(['context-gate.profile' => 'strict', 'app.env' => 'local', 'context-gate.http.refusals_per_minute' => null]);

        $this->assertSame(60, RefusalLedger::limit());
    }

    public function test_refusal_ledger_uses_the_balanced_refusal_limit(): void
    {
        config(['context-gate.profile' => 'balanced', 'app.env' => 'local', 'context-gate.http.refusals_per_minute' => null]);

        $this->assertSame(60, RefusalLedger::limit());
    }

    /** @return array{timeout: float, maxBytes: int} the limits the transport received */
    private function openApiFetchUnder(?string $profile): array
    {
        config(['context-gate.profile' => $profile]);
        config(['context-gate.sources.openapi.paths' => ['openapi/']]);
        config(['context-gate.sources.openapi.allowlist' => ['api.example.test']]);
        $fixture = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/openapi/valid-3.1.json');
        $transport = new FakeTransport(new HttpReply(200, 'application/json', $fixture));

        (new OpenApiEndpointsTool(new FakeResolver([['203.0.113.10']]), $transport))->handle(['url' => self::URL]);

        return ['timeout' => $transport->calls[0]['timeout'], 'maxBytes' => $transport->calls[0]['maxBytes']];
    }

    public function test_openapi_fetch_uses_the_strict_timeout_and_size(): void
    {
        $this->assertSame(['timeout' => 2.5, 'maxBytes' => 1048576], $this->openApiFetchUnder('strict'));
    }

    public function test_openapi_fetch_uses_the_code_caps_under_balanced(): void
    {
        $this->assertSame(['timeout' => 5.0, 'maxBytes' => 2097152], $this->openApiFetchUnder('balanced'));
    }
}
