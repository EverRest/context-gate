<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;
use ContextGate\Server\GateServer;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Transport\FakeTransporter;

/** SC-001 and FR-005: a seeded secret value never appears in a response, in any form. */
class SecretNeverReturnedTest extends TestCase
{
    public const PASSWORD = 'FAKE_FIXTURE_ONLY_pw_5e1c9a7b3d';

    public function test_no_response_contains_the_seeded_password_in_any_form(): void
    {
        Gate::define('echo.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => ['account_lookup']]);
        config(['context-gate.classification' => ['secret_accounts' => ['name' => 'public', 'password' => 'secret']]]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'account_lookup' => [
                'enabled' => true,
                'class' => AccountLookupTool::class,
                'contract' => AccountNameContract::class,
                'gate' => 'echo.allow',
                'fingerprint' => hash('sha256', (new AccountLookupTool())->description()),
            ],
        ]]);

        $raw = $this->send(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'account_lookup', 'arguments' => ['text' => 'ACC_1']],
        ], JSON_THROW_ON_ERROR))[0];

        $response = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($response['result']['isError']);
        $this->assertSame(['name' => 'Ada'], json_decode($response['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR));

        foreach ($this->formsOf(self::PASSWORD) as $label => $form) {
            $this->assertStringNotContainsString($form, $raw, "response leaks the password as {$label}");
        }
    }

    /** @return array<string, string> */
    private function formsOf(string $value): array
    {
        return [
            'raw' => $value,
            'json escaped' => substr((string) json_encode($value, JSON_UNESCAPED_SLASHES), 1, -1),
            'url encoded' => rawurlencode($value),
            'base64' => base64_encode($value),
            'hex' => bin2hex($value),
            'uppercase' => strtoupper($value),
        ];
    }

    /** @return list<string> */
    private function send(string ...$messages): array
    {
        $transport = new class () extends FakeTransporter {
            /** @var list<string> */
            public array $sent = [];

            public function send(string $message): void
            {
                $this->sent[] = $message;
            }
        };

        $server = new GateServer($transport);
        $server->start();

        foreach ($messages as $message) {
            $server->handle($message);
        }

        return $transport->sent;
    }
}

final class AccountLookupTool extends EchoTool
{
    public function name(): string
    {
        return 'account_lookup';
    }

    public function handle(array $arguments): Response
    {
        return Response::text(json_encode([
            'name' => 'Ada',
            'password' => SecretNeverReturnedTest::PASSWORD,
        ], JSON_THROW_ON_ERROR));
    }
}

final class AccountNameContract extends ContextContract
{
    public function fields(): array
    {
        return ['name' => Field::plain()];
    }

    public function model(): string
    {
        return SecretAccount::class;
    }
}

final class SecretAccount extends Model
{
    protected $table = 'secret_accounts';
}
