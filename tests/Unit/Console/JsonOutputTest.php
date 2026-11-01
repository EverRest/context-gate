<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\Contract\GateCallHarness;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/** gate:token --list, gate:preview and gate:check print pure JSON on stdout with --json. */
class JsonOutputTest extends TestCase
{
    use GateCallHarness;

    #[Test]
    public function token_list_json_is_an_array_of_metadata_without_any_token_value(): void
    {
        TokenFactory::migrate();
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info');
        Artisan::call('gate:token', ['--holder' => 'Ivan', '--issuer' => 'Pavlo', '--ceiling' => 'internal', '--expires' => '30d']);
        $issued = Artisan::output();
        preg_match('/^\d+\|\S+$/m', $issued, $plain);

        $this->assertSame(0, Artisan::call('gate:token', ['--list' => true, '--json' => true]));

        $out = Artisan::output();
        $rows = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $rows);
        $this->assertSame(['id', 'holder', 'issuer', 'ceiling', 'expires_at', 'issued_at', 'caller'], array_keys($rows[0]));
        $this->assertSame('Ivan', $rows[0]['holder']);
        $this->assertCount(1, $plain);
        $this->assertStringNotContainsString(explode('|', (string) ($plain[0] ?? ''))[1], $out);
    }

    #[Test]
    public function token_list_json_with_no_tokens_is_an_empty_array(): void
    {
        TokenFactory::migrate();

        Artisan::call('gate:token', ['--list' => true, '--json' => true]);

        $this->assertSame([], json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function preview_json_has_the_text_truncated_and_dropped(): void
    {
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['id' => 'public', 'status' => 'public', 'note' => 'internal', 'email' => 'personal']]);
        ProbeTool::$output = (string) json_encode([['id' => 42, 'status' => 'paid', 'email' => 'jane@example.invalid']]);

        $this->assertSame(0, Artisan::call('gate:preview', ['tool' => 'probe', '--args' => '{"text":"x"}', '--json' => true]));

        $data = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['text', 'truncated', 'dropped'], array_keys($data));
        $this->assertStringContainsString('paid', $data['text']);
        $this->assertStringNotContainsString('jane@example.invalid', $data['text']);
        $this->assertIsArray($data['dropped']);
    }

    #[Test]
    public function preview_json_failure_keeps_stdout_empty_and_exits_1(): void
    {
        [$output, $stdout, $stderr] = $this->splitOutput();

        $this->assertSame(1, Artisan::call('gate:preview', ['tool' => 'probe', '--args' => '[', '--json' => true], $output));

        $this->assertSame('', $stdout->fetch());
        $this->assertStringContainsString('--args must be a JSON object', $stderr->fetch());
    }

    #[Test]
    public function check_json_with_a_failure_is_still_valid_json_and_exits_1(): void
    {
        TokenFactory::migrate();
        config(['context-gate.firewall.ceiling' => 'bogus']);

        $this->assertSame(1, Artisan::call('gate:check', ['--json' => true]));

        $rows = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains('fail', array_column($rows, 'status'));
    }

    #[Test]
    public function audit_json_failure_prints_the_error_to_stderr_not_stdout(): void
    {
        config(['context-gate.classification' => ['t' => ['c' => 'nonsense']]]);
        [$output, $stdout, $stderr] = $this->splitOutput();

        $this->assertSame(1, Artisan::call('gate:audit', ['--json' => true], $output));

        $this->assertSame('', $stdout->fetch());
        $this->assertStringContainsString('classification: Unknown class', $stderr->fetch());
    }

    /** @return array{ConsoleOutputInterface, BufferedOutput, BufferedOutput} an output with separate stdout and stderr buffers */
    private function splitOutput(): array
    {
        [$stdout, $stderr] = [new BufferedOutput(), new BufferedOutput()];
        $output = new class ($stdout, $stderr) extends BufferedOutput implements ConsoleOutputInterface {
            public function __construct(private readonly BufferedOutput $out, private readonly BufferedOutput $err)
            {
                parent::__construct();
            }

            public function doWrite(string $message, bool $newline): void
            {
                $this->out->write($message, $newline);
            }

            public function getErrorOutput(): OutputInterface
            {
                return $this->err;
            }

            public function setErrorOutput(OutputInterface $error): void
            {
            }

            public function section(): ConsoleSectionOutput
            {
                throw new LogicException('unused');
            }
        };

        return [$output, $stdout, $stderr];
    }
}
