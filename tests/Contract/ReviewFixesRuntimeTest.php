<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Classification\Ceiling;
use ContextGate\Server\Caller;
use ContextGate\Settings\ActiveSettings;
use ContextGate\Server\GateServer;
use ContextGate\Tests\Fixtures\CanaryValues;
use ContextGate\Tests\Fixtures\PlainPersonalContract;
use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Transport\HttpTransport;
use ContextGate\Privacy\Pseudonymizer;
use RuntimeException;

/** Task 1, 2, 3 and 7 of the review fixes, through the real call path. */
class ReviewFixesRuntimeTest extends TestCase
{
    use GateCallHarness;

    protected function tearDown(): void
    {
        Pseudonymizer::usePersistentSessions(false);

        parent::tearDown();
    }

    public function test_a_contract_that_breaks_a_load_rule_is_refused_per_call_before_the_handler(): void
    {
        $this->recordLines();
        $this->configureTool(PlainPersonalContract::class, ['customers' => ['status' => 'public', 'email' => 'personal']]);
        ProbeTool::$output = (string) json_encode([['status' => 'paid', 'email' => 'jane@example.invalid']]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertTrue($result['isError']);
        $this->assertSame('Request refused.', $result['content'][0]['text']);
        $this->assertFalse(ProbeTool::$called);
        $receipt = json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('config.invalid', $receipt['reason']);
    }

    public function test_the_validation_follows_a_classification_change_in_a_new_process(): void
    {
        $this->recordLines();
        $this->configureTool(PlainPersonalContract::class, ['customers' => ['status' => 'public', 'email' => 'personal']]);
        ProbeTool::$output = (string) json_encode([['status' => 'paid']]);
        $this->assertTrue($this->callTool('probe', ['text' => 'x'])['isError']);

        // The document is validated once per process (spec 009): a new process is a new instance.
        config(['context-gate.classification' => ['customers' => ['status' => 'public', 'email' => 'internal']]]);
        app()->forgetInstance(ActiveSettings::class);

        $this->assertFalse($this->callTool('probe', ['text' => 'x'])['isError']);
    }

    public function test_a_handler_failure_notes_only_metadata_on_the_receipt_channel(): void
    {
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public', 'note' => 'internal', 'email' => 'personal']]);
        ProbeTool::$output = (string) json_encode([['status' => 'paid', 'note' => 'hi', 'email' => 'a@example.invalid']]);
        ProbeTool::$throw = new RuntimeException('FAKE_FIXTURE_ONLY-secret');

        try {
            $result = $this->callTool('probe', ['text' => 'x']);
        } finally {
            ProbeTool::$throw = null;
        }

        $this->assertTrue($result['isError']);
        $all = implode("\n", $this->lines);
        $this->assertStringNotContainsString('FAKE_FIXTURE_ONLY-secret', $all);
        $notes = array_values(array_filter(array_map(
            fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $this->lines,
        ), static fn (array $record): bool => isset($record['exception'])));
        $this->assertCount(1, $notes);
        $this->assertSame(RuntimeException::class, $notes[0]['exception']);
        $this->assertSame('ReviewFixesRuntimeTest.php', $notes[0]['file']);
        $this->assertIsInt($notes[0]['line']);
        $this->assertMatchesRegularExpression('/^r_[0-9a-f]{8}$/', $notes[0]['reference']);
        $this->assertSame(['reference', 'exception', 'file', 'line'], array_keys($notes[0]));
    }

    public function test_a_canary_inside_a_text_field_is_found_before_redaction(): void
    {
        $this->recordLines();
        config(['context-gate.canaries.values' => [CanaryValues::EMAIL]]);
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public', 'note' => 'internal', 'email' => 'personal']]);
        ProbeTool::$output = (string) json_encode([['id' => 1, 'status' => 'paid', 'note' => 'mail '.CanaryValues::EMAIL, 'email' => 'a@example.invalid']]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertTrue($result['isError']);
        $this->assertSame('canary.hit', $this->lastReceipt()['reason']);
    }

    public function test_the_http_transport_turns_on_persistent_session_pseudonyms(): void
    {
        $this->assertFalse($this->sessionSurvivesFlush());

        (new GateServer(new HttpTransport(Request::create('/mcp', 'POST'))))->start();

        $this->assertTrue($this->sessionSurvivesFlush());
    }

    private function sessionSurvivesFlush(): bool
    {
        $scope = 'session:'.uniqid('', true);
        $pseudonym = (new Pseudonymizer())->pseudonym('USR', 'customers', '7', $scope);
        Pseudonymizer::flushSessions();

        return (new Pseudonymizer())->resolve($pseudonym, $scope, 'alice') !== null;
    }

    public function test_the_ceiling_is_the_container_value_over_http_and_the_config_otherwise(): void
    {
        config(['context-gate.firewall.ceiling' => 'public']);
        $this->assertSame(Ceiling::Public, Caller::ceiling());

        app()->instance('context-gate.ceiling', 'personal');
        $this->assertSame(Ceiling::Personal, Caller::ceiling());

        app()->instance('context-gate.ceiling', '');
        $this->assertNull(Caller::ceiling());
    }
}
