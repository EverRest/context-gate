<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Roles;

use ContextGate\Tests\Contract\GateCallHarness;
use ContextGate\Tests\Fixtures\RoleFixture;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

/** spec 010 US4, FR-009: over stdio the OS user name maps to a role; an unmapped user has no tools. */
class StdioRoleTest extends TestCase
{
    use GateCallHarness;

    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        $this->recordLines();
        Gate::define('echo.allow', fn (): bool => true);
        config(['context-gate.firewall.ceiling' => 'internal']);
    }

    public function test_a_mapped_user_gets_the_tools_of_its_role(): void
    {
        $this->configureTool('ContextGate\\Tests\\Fixtures\\ProbeContract', ['customers' => ['id' => 'public']]);
        app()->instance('context-gate.caller', 'dev');
        config(RoleFixture::stdio('dev', ['probe']));
        config(['context-gate.roles.dev.ceiling' => 'personal']);

        $names = $this->listed();

        $this->assertSame(['probe'], $names);
    }

    public function test_an_unmapped_user_lists_nothing_and_a_call_records_role_unknown(): void
    {
        $this->configureTool('ContextGate\\Tests\\Fixtures\\ProbeContract', ['customers' => ['id' => 'public']]);
        app()->instance('context-gate.caller', 'stranger');
        config(RoleFixture::stdio('dev', ['probe']));

        $this->assertSame([], $this->listed());

        $this->send((string) json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'probe', 'arguments' => ['text' => 'x']]], JSON_THROW_ON_ERROR));
        $this->assertSame('role.unknown', $this->lastReceipt()['reason']);
    }

    public function test_the_stdio_ceiling_is_the_narrower_of_firewall_and_role(): void
    {
        app()->instance('context-gate.caller', 'dev');
        config(RoleFixture::stdio('dev', []));
        config(['context-gate.roles.dev.ceiling' => 'public']);

        $this->assertSame('public', \ContextGate\Server\Caller::ceiling()?->value);
    }

    public function test_every_receipt_of_these_calls_carries_a_role(): void
    {
        $this->configureTool('ContextGate\\Tests\\Fixtures\\ProbeContract', ['customers' => ['id' => 'public']]);
        app()->instance('context-gate.caller', 'stranger');
        config(RoleFixture::stdio('dev', ['probe']));

        $this->listed();
        $this->send((string) json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'probe', 'arguments' => ['text' => 'x']]], JSON_THROW_ON_ERROR));

        $receipts = array_filter(array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $this->lines), static fn (array $record): bool => array_key_exists('decision', $record));
        $this->assertNotEmpty($receipts);
        foreach ($receipts as $receipt) {
            $this->assertSame('none', $receipt['role']);
        }
    }

    /** @return list<string> */
    private function listed(): array
    {
        $raw = $this->send((string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], JSON_THROW_ON_ERROR))[0];
        $tools = json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['result']['tools'];

        return array_column($tools, 'name');
    }
}
