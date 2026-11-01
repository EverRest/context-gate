<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Contracts\AuthorizesTools;
use ContextGate\Contracts\IssuesPseudonyms;
use ContextGate\Contracts\RedactsText;
use ContextGate\Policy\ToolPolicy;
use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Privacy\Redactor;
use ContextGate\Server\Caller;
use ContextGate\Server\Fingerprint;
use ContextGate\Server\GateServer;
use ContextGate\Server\Tool;
use ContextGate\Server\ToolRegistry;
use ContextGate\Source\Fetch\HttpReply;
use ContextGate\Source\Fetch\Transport;
use ContextGate\Source\OpenApiSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\Fakes\FakeResolver;
use ContextGate\Tests\Fakes\FakeTransport;
use ContextGate\Tests\Fixtures\CustomerContract;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use PHPUnit\Framework\Attributes\Group;

class DependentTool extends Tool
{
    public function __construct(public readonly Redactor $redactor)
    {
    }

    public function name(): string
    {
        return 'dependent';
    }

    public function description(): string
    {
        return 'Needs a container-resolved dependency.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function handle(array $arguments): Response
    {
        return Response::text('ok');
    }
}

class TaggingRedactor extends Redactor
{
    public function redact(string $text): string
    {
        return 'HOST-REDACTOR';
    }
}

class DenyAllPolicy extends ToolPolicy
{
    public function allows(string $tool, Caller $caller, array $arguments): bool
    {
        return false;
    }
}

class PlainRedactor implements RedactsText
{
    public function redact(string $text): string
    {
        return 'PLAIN-REDACTOR';
    }
}

class PlainPolicy implements AuthorizesTools
{
    public function allows(string $tool, Caller $caller, array $arguments): bool
    {
        return false;
    }
}

class PlainPseudonyms implements IssuesPseudonyms
{
    public function pseudonym(string $prefix, string $table, string $id, string $scope, ?string $caller = null): string
    {
        return 'plain_'.$id;
    }

    public function pseudonyms(string $prefix, string $table, array $ids, string $scope, ?string $caller = null): array
    {
        return array_combine($ids, array_map(fn (string $id): string => 'plain_'.$id, $ids));
    }

    public function resolve(string $pseudonym, string $scope, ?string $caller = null): ?array
    {
        return ['table' => 'customers', 'id' => substr($pseudonym, 6)];
    }
}

class SwappedContract extends CustomerContract
{
}

class MailTool extends EchoTool
{
    public function name(): string
    {
        return 'customer_lookup';
    }

    public function handle(array $arguments): Response
    {
        return Response::text((string) json_encode(['customer' => 'USR_1', 'email' => 'someone@example.test']));
    }
}

/** Each extension point resolves through the container, so a host can bind its own class. */
class ExtensionPointsTest extends TestCase
{
    public function test_defaults_resolve_to_fresh_instances(): void
    {
        $this->assertNotSame(app(Redactor::class), app(Redactor::class));
        $this->assertNotSame(app(ToolPolicy::class), app(ToolPolicy::class));
    }

    public function test_interfaces_resolve_to_the_concrete_defaults(): void
    {
        $this->assertInstanceOf(Redactor::class, app(RedactsText::class));
        $this->assertInstanceOf(Pseudonymizer::class, app(IssuesPseudonyms::class));
        $this->assertInstanceOf(ToolPolicy::class, app(AuthorizesTools::class));
    }

    public function test_each_interface_resolves_to_its_default_class(): void
    {
        $this->assertSame(Redactor::class, app(RedactsText::class)::class);
        $this->assertSame(Pseudonymizer::class, app(IssuesPseudonyms::class)::class);
        $this->assertSame(ToolPolicy::class, app(AuthorizesTools::class)::class);
    }

    public function test_the_old_concrete_names_are_not_container_bindings(): void
    {
        $this->assertFalse(app()->bound(Redactor::class));
        $this->assertFalse(app()->bound(Pseudonymizer::class));
        $this->assertFalse(app()->bound(ToolPolicy::class));
    }

    #[Group('finding-concrete-binding-keeps-interface')]
    public function test_binding_the_concrete_class_does_not_replace_the_interface(): void
    {
        app()->bind(Redactor::class, TaggingRedactor::class);
        app()->bind(ToolPolicy::class, DenyAllPolicy::class);

        $this->assertSame(Redactor::class, app(RedactsText::class)::class);
        $this->assertSame(ToolPolicy::class, app(AuthorizesTools::class)::class);
    }

    public function test_the_firewall_uses_an_interface_only_redactor(): void
    {
        app()->bind(RedactsText::class, PlainRedactor::class);
        $this->configure();

        $this->assertStringContainsString('PLAIN-REDACTOR', $this->callLookup()['content'][0]['text']);
    }

    public function test_the_call_uses_an_interface_only_policy(): void
    {
        app()->bind(AuthorizesTools::class, PlainPolicy::class);
        $this->configure();

        $this->assertTrue($this->callLookup()['isError']);
    }

    public function test_an_interface_only_pseudonym_issuer_is_bound(): void
    {
        app()->bind(IssuesPseudonyms::class, PlainPseudonyms::class);

        $record = app(IssuesPseudonyms::class)->resolve('plain_7', 'session');

        $this->assertSame(['table' => 'customers', 'id' => '7'], $record);
    }

    public function test_a_tool_with_a_constructor_dependency_is_resolved_by_the_container(): void
    {
        app()->bind(Redactor::class, TaggingRedactor::class);

        $tool = (new ToolRegistry(['dependent' => ['class' => DependentTool::class]]))->find('dependent');

        $this->assertInstanceOf(DependentTool::class, $tool);
        $this->assertInstanceOf(TaggingRedactor::class, $tool->redactor);
    }

    public function test_a_contract_is_resolved_by_the_container(): void
    {
        app()->bind(CustomerContract::class, SwappedContract::class);
        config(['context-gate.tools' => ['echo' => ['class' => EchoTool::class, 'contract' => CustomerContract::class]]]);

        $this->assertInstanceOf(SwappedContract::class, ToolRegistry::contractFor('echo'));
    }

    public function test_the_firewall_uses_the_redactor_bound_to_its_interface(): void
    {
        app()->bind(RedactsText::class, TaggingRedactor::class);
        $this->configure();

        $text = $this->callLookup()['content'][0]['text'];

        $this->assertStringContainsString('HOST-REDACTOR', $text);
        $this->assertStringNotContainsString('someone@example.test', $text);
    }

    public function test_the_call_uses_the_policy_bound_to_its_interface(): void
    {
        app()->bind(AuthorizesTools::class, DenyAllPolicy::class);
        $this->configure();

        $this->assertTrue($this->callLookup()['isError']);
    }

    public function test_the_openapi_source_uses_the_bound_transport(): void
    {
        $fixture = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/openapi/valid-3.1.json');
        $transport = new FakeTransport(new HttpReply(200, 'application/json', $fixture));
        app()->instance(Transport::class, $transport);
        app()->instance(\ContextGate\Source\Fetch\Resolver::class, new FakeResolver([['203.0.113.10'], ['203.0.113.10']]));

        $result = (new OpenApiSource(dirname(__DIR__, 2).'/Fixtures', ['openapi/'], ['api.example.test']))
            ->read(new SourceRequest(['url' => 'https://api.example.test/openapi.json']));

        $this->assertTrue($result->ok);
        $this->assertCount(1, $transport->calls);
    }

    private function configure(): void
    {
        Gate::define('echo.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => ['customer_lookup']]);
        config(['context-gate.classification' => ['customers' => ['customer' => 'public', 'email' => 'internal']]]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'customer_lookup' => [
                'enabled' => true,
                'class' => MailTool::class,
                'contract' => CustomerContract::class,
                'gate' => 'echo.allow',
                'fingerprint' => Fingerprint::of(new MailTool()),
            ],
        ]]);
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info', 'error')->andReturnNull();
    }

    /** @return array<string, mixed> */
    private function callLookup(): array
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
        $server->handle((string) json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'customer_lookup', 'arguments' => ['text' => 'x']],
        ]));

        return json_decode($transport->sent[0], true, 512, JSON_THROW_ON_ERROR)['result'];
    }
}
