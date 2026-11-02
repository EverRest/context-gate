<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Server\Fingerprint;
use ContextGate\Tests\Contract\GateCallHarness;
use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/** spec 009 US4 scenario 2, FR-012: a call's classification hash covers the ceiling that applies to that call. */
class RuleHashEffectiveTest extends TestCase
{
    use GateCallHarness;

    /** @var list<string> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        Gate::define('echo.allow', fn (): bool => true);
        ProbeTool::$output = (string) json_encode([['status' => 'paid']]);
    }

    /** Over HTTP the token's ceiling is the one the hash covers; the configured stdio ceiling is not. */
    public function test_over_http_each_token_ceiling_gives_its_own_classification_hash(): void
    {
        config(['context-gate.firewall.ceiling' => 'internal']);
        config(['context-gate.classification' => ['customers' => ['status' => 'public', 'note' => 'internal', 'email' => 'personal']]]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => ['probe' => [
            'enabled' => true,
            'class' => ProbeTool::class,
            'contract' => ProbeContract::class,
            'gate' => 'echo.allow',
            'fingerprint' => Fingerprint::of(new ProbeTool()),
        ]]]);
        $public = TokenFactory::create(['gate:read', 'gate:ceiling:public']);
        $internal = TokenFactory::create(['gate:read', 'gate:ceiling:internal']);
        config(['context-gate.callers.token:'.$public->accessToken->getKey().'.tools' => ['probe']]);
        config(['context-gate.callers.token:'.$internal->accessToken->getKey().'.tools' => ['probe']]);
        $this->captureLog();

        $this->postAs($public->plainTextToken);
        $publicHash = $this->classificationHashOfLastProbeReceipt();
        $this->postAs($internal->plainTextToken);
        $internalHash = $this->classificationHashOfLastProbeReceipt();

        $this->assertNotSame($publicHash, $internalHash);
    }

    /** Over stdio the configured ceiling is the one the hash covers. */
    public function test_over_stdio_the_configured_ceiling_is_the_one_the_hash_covers(): void
    {
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public', 'note' => 'internal', 'email' => 'personal']]);

        config(['context-gate.firewall.ceiling' => 'public']);
        $this->callTool('probe', ['text' => 'x']);
        $public = $this->lastReceipt()['rules']['classification'];

        config(['context-gate.firewall.ceiling' => 'internal']);
        $this->callTool('probe', ['text' => 'x']);
        $internal = $this->lastReceipt()['rules']['classification'];

        $this->assertNotSame($public, $internal);
    }

    private function captureLog(): void
    {
        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->captured[] = $line;
        });
    }

    private function postAs(string $bearer): void
    {
        // A request is a new process: the guard must not keep the user of the previous request.
        app('auth')->forgetGuards();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$bearer,
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
        ])->postJson('/'.config('context-gate.http.path'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'probe', 'arguments' => ['text' => 'x']],
        ])->assertOk();
    }

    private function classificationHashOfLastProbeReceipt(): string
    {
        $records = array_map(static fn (string $line): mixed => json_decode($line, true), $this->captured);
        $receipts = array_values(array_filter(
            $records,
            static fn (mixed $record): bool => is_array($record) && ($record['tool'] ?? null) === 'probe' && array_key_exists('decision', $record),
        ));

        $this->assertNotSame([], $receipts, 'no receipt for the probe call');

        return (string) $receipts[count($receipts) - 1]['rules']['classification'];
    }
}
