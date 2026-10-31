<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;
use ContextGate\Server\FirewallRunner;
use ContextGate\Server\GateServer;
use ContextGate\Tests\Fixtures\CanaryValues;
use ContextGate\Tests\Fixtures\CustomerRecord;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Transport\FakeTransporter;

/**
 * T086: the firewall stages run in the fixed order of contracts/firewall-pipeline.md for every allowed call, and
 * the wiring keeps its promises: pseudonyms, nested relations, budget truncation and rate-limit refusal.
 */
class PipelineOrderTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    /** The stages of one allowed call, in the order of contracts/firewall-pipeline.md. */
    private const ORDER = [
        'fetch', 'classify', 'project', 'relations', 'transform', 'normalize',
        'redact', 'canary', 'quarantine', 'budget', 'receipt',
    ];

    public function test_every_stage_runs_once_in_the_fixed_order(): void
    {
        $runner = new StageRecordingRunner();
        app()->instance(FirewallRunner::class, $runner);
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public', 'note' => 'internal', 'email' => 'personal']]);
        ProbeTool::$output = (string) json_encode([['id' => 42, 'status' => 'paid', 'note' => 'hello', 'email' => 'a@example.invalid']]);

        $this->callTool('probe', ['text' => 'x']);

        $this->assertSame(self::ORDER, $runner->stages);
    }

    public function test_a_pseudonym_field_is_returned_as_a_pseudonym_and_never_raw(): void
    {
        $this->configureTool(ProbeContract::class, ['customers' => ['id' => 'public', 'status' => 'public', 'email' => 'personal']]);
        ProbeTool::$output = (string) json_encode([['id' => 42, 'status' => 'paid', 'email' => 'jane@example.invalid']]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertFalse($result['isError']);
        $this->assertStringNotContainsString('jane@example.invalid', $result['content'][0]['text']);
        $this->assertMatchesRegularExpression('/"email":"USR_[0-9a-f]{10}"/', $result['content'][0]['text']);
    }

    public function test_a_relation_is_a_nested_object_and_null_when_the_related_row_is_missing(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        DB::table('customers')->insert(['id' => 1, 'name' => 'Ada']);
        $this->configureTool(RelationProbeContract::class, [
            'orders' => ['status' => 'public', 'customer_id' => 'internal'],
            'customers' => ['id' => 'public', 'name' => 'public'],
        ]);
        ProbeTool::$output = (string) json_encode([['status' => 'paid', 'customer_id' => 1], ['status' => 'open', 'customer_id' => 99]]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertSame(
            [['status' => 'paid', 'customer_id' => ['name' => 'Ada']], ['status' => 'open', 'customer_id' => null]],
            json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function test_an_output_over_the_per_call_limit_is_truncated_and_marked_in_the_structured_content(): void
    {
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public']]);
        ProbeTool::$output = (string) json_encode(array_fill(0, 300, ['status' => str_repeat('a', 100)]));

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertFalse($result['isError']);
        $this->assertTrue($result['structuredContent']['truncated']);
        $this->assertLessThan(300, count(json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR)));
        $this->assertSame([], $result['structuredContent']['flags']);
    }

    public function test_a_call_over_the_per_minute_limit_is_refused_with_the_retry_time(): void
    {
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public']]);
        ProbeTool::$output = (string) json_encode([['status' => 'paid']]);
        $messages = [];

        for ($id = 1; $id <= 61; $id++) {
            $messages[] = (string) json_encode([
                'jsonrpc' => '2.0',
                'id' => $id,
                'method' => 'tools/call',
                'params' => ['name' => 'probe', 'arguments' => ['text' => 'x']],
            ], JSON_THROW_ON_ERROR);
        }

        $this->recordLines();
        $raw = $this->send(...$messages);
        $last = json_decode((string) end($raw), true, 512, JSON_THROW_ON_ERROR)['result'];

        $this->assertTrue($last['isError']);
        $this->assertMatchesRegularExpression('/^Request refused\. Retry after \d+ seconds\.$/', $last['content'][0]['text']);
        $receipt = json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('rate.limited', $receipt['reason']);
        $this->assertSame('refused', $receipt['decision']);
    }

    /** The production path has no test seam: a stage observer bound in the container is never called. */
    public function test_a_stage_observer_bound_in_the_container_is_not_called(): void
    {
        $told = [];
        app()->instance('context-gate.stage_observer', static function (string $stage) use (&$told): void {
            $told[] = $stage;
        });
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public']]);
        ProbeTool::$output = (string) json_encode([['status' => 'paid']]);

        $this->callTool('probe', ['text' => 'x']);

        $this->assertSame([], $told);
    }

    /** FR-021: a refused call still counts, so refusals before the stages cannot be used to probe past the limit. */
    public function test_calls_refused_before_the_stages_count_toward_the_per_minute_limit(): void
    {
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public', 'email' => 'personal']]);
        ProbeTool::$output = (string) json_encode([['status' => 'paid']]);
        $messages = [];

        // Sixty calls with a pseudonym that resolves to nothing are refused before any stage runs.
        for ($id = 1; $id <= 61; $id++) {
            $arguments = $id < 61 ? ['email' => 'USR_0000000000'] : ['text' => 'x'];
            $messages[] = (string) json_encode([
                'jsonrpc' => '2.0',
                'id' => $id,
                'method' => 'tools/call',
                'params' => ['name' => 'probe', 'arguments' => $arguments],
            ], JSON_THROW_ON_ERROR);
        }

        $this->recordLines();
        $raw = $this->send(...$messages);
        $last = json_decode((string) end($raw), true, 512, JSON_THROW_ON_ERROR)['result'];

        $this->assertTrue($last['isError']);
        $this->assertMatchesRegularExpression('/^Request refused\. Retry after \d+ seconds\.$/', $last['content'][0]['text']);
        $receipt = json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('rate.limited', $receipt['reason']);
    }

    /** R8 and US5 scenario 3: a canary in the arguments refuses the call before the handler runs, after normalisation. */
    public function test_a_canary_in_the_arguments_refuses_the_call_before_the_handler_runs(): void
    {
        $this->recordLines();
        config(['context-gate.canaries.values' => [CanaryValues::TOKEN]]);
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public']]);
        ProbeTool::$output = (string) json_encode([['status' => 'paid']]);

        // A zero-width space inside the canary is removed by the normalisation, so the canary is still found.
        $split = substr(CanaryValues::TOKEN, 0, 6)."\u{200B}".substr(CanaryValues::TOKEN, 6);
        $result = $this->callTool('probe', ['text' => $split]);

        $this->assertTrue($result['isError']);
        $this->assertSame('Request refused.', $result['content'][0]['text']);
        $this->assertFalse(ProbeTool::$called);
        $receipt = json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('canary.hit', $receipt['reason']);
        $this->assertStringNotContainsString(CanaryValues::TOKEN, (string) end($this->lines));
    }

    /** An env() value that is unset reads as null; the canary check takes strings only, so null must be dropped. */
    public function test_an_unset_canary_value_does_not_fail_every_call(): void
    {
        config(['context-gate.canaries.values' => [null, '']]);
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public']]);
        ProbeTool::$output = (string) json_encode([['status' => 'paid']]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertFalse($result['isError']);
    }

    /** The set canary values still block: the unset ones are dropped, not the whole list. */
    public function test_a_set_canary_value_still_blocks_when_an_unset_one_is_configured(): void
    {
        $this->recordLines();
        config(['context-gate.canaries.values' => [null, CanaryValues::TOKEN]]);
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public']]);
        ProbeTool::$output = (string) json_encode([['status' => CanaryValues::TOKEN]]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertTrue($result['isError']);
        $receipt = json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('canary.hit', $receipt['reason']);
    }

    private function recordLines(): void
    {
        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->lines[] = $line;
        });
    }

    /**
     * @param class-string<ContextContract> $contract
     * @param array<string, array<string, string>> $classes
     */
    private function configureTool(string $contract, array $classes): void
    {
        Gate::define('echo.allow', fn (): bool => true);
        ProbeTool::$called = false;
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => ['probe']]);
        config(['context-gate.classification' => $classes]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'probe' => [
                'enabled' => true,
                'class' => ProbeTool::class,
                'contract' => $contract,
                'gate' => 'echo.allow',
                'fingerprint' => hash('sha256', (new ProbeTool())->description()),
            ],
        ]]);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> the result object of one tools/call
     */
    private function callTool(string $name, array $arguments): array
    {
        $raw = $this->send((string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], JSON_THROW_ON_ERROR))[0];

        /** @var array<string, mixed> $result */
        $result = json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['result'];

        return $result;
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

final class ProbeTool extends EchoTool
{
    public static string $output = '[]';

    public static bool $called = false;

    public function name(): string
    {
        return 'probe';
    }

    public function handle(array $arguments): Response
    {
        self::$called = true;

        return Response::text(self::$output);
    }
}

class ProbeContract extends ContextContract
{
    public function fields(): array
    {
        return [
            'status' => Field::plain(),
            'note' => Field::text(),
            'email' => Field::pseudonym('USR'),
        ];
    }

    public function model(): string
    {
        return CustomerRecord::class;
    }
}

class RelationProbeContract extends ContextContract
{
    public function fields(): array
    {
        return [
            'status' => Field::plain(),
            'customer_id' => Field::relation(RelatedProbeContract::class),
        ];
    }

    public function model(): string
    {
        return ProbeOrderModel::class;
    }
}

class RelatedProbeContract extends ContextContract
{
    public function fields(): array
    {
        return ['name' => Field::plain()];
    }

    public function model(): string
    {
        return CustomerRecord::class;
    }
}

class ProbeOrderModel extends Model
{
    protected $table = 'orders';
}

/** Records each stage mark of the runner, in the order a call reaches it. */
final class StageRecordingRunner extends FirewallRunner
{
    /** @var list<string> */
    public array $stages = [];

    public function stage(string $name): void
    {
        $this->stages[] = $name;
    }
}
