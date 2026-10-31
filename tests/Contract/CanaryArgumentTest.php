<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Pipeline\CanaryCheck;
use ContextGate\Pipeline\Normalizer;
use ContextGate\Pipeline\Payload;
use ContextGate\Tests\Fixtures\CanaryValues;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Log;

/**
 * User Story 5, scenario 3. Harness note: as in CanaryBlockTest, GateCallTool does not run the canary stage until
 * T086, so the arguments are checked by a minimal stage composition here, and the receipt line is written by the
 * harness.
 */
class CanaryArgumentTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    public function test_a_canary_in_the_arguments_refuses_the_call_before_the_handler_runs(): void
    {
        $this->recordLines();
        $handlerCalled = false;

        $text = $this->gateArguments(
            ['customer_id' => 'USR_7f3a', 'note' => 'token ' . CanaryValues::TOKEN],
            function () use (&$handlerCalled): void {
                $handlerCalled = true;
            },
        );

        $this->assertSame('Request refused.', $text);
        $this->assertFalse($handlerCalled);
    }

    public function test_the_refused_argument_call_is_recorded_without_the_canary_value(): void
    {
        $this->recordLines();

        $this->gateArguments(['note' => 'token ' . CanaryValues::TOKEN], static fn (): null => null);

        $this->assertCount(1, $this->lines);
        $receipt = json_decode($this->lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($receipt);
        $this->assertSame('canary.hit', $receipt['reason']);
        $this->assertStringNotContainsString(CanaryValues::TOKEN, $this->lines[0]);
    }

    public function test_arguments_without_a_canary_reach_the_handler(): void
    {
        $this->recordLines();
        $handlerCalled = false;

        $text = $this->gateArguments(
            ['customer_id' => 'USR_7f3a'],
            function () use (&$handlerCalled): void {
                $handlerCalled = true;
            },
        );

        $this->assertSame('ok', $text);
        $this->assertTrue($handlerCalled);
        $this->assertSame([], $this->lines);
    }

    /**
     * @param array<string, string> $arguments
     */
    private function gateArguments(array $arguments, \Closure $handler): string
    {
        $payload = (new Normalizer(array_keys($arguments)))->handle(
            new Payload([$arguments]),
            static fn (Payload $next): Payload => $next,
        );
        $checked = (new CanaryCheck([CanaryValues::EMAIL, CanaryValues::TOKEN]))->handle(
            $payload,
            static fn (Payload $next): Payload => $next,
        );

        if (in_array(CanaryCheck::FLAG, $checked->flags, true)) {
            Log::channel('context-gate')->info(json_encode([
                'id' => 'r_' . bin2hex(random_bytes(4)),
                'decision' => 'refused',
                'reason' => CanaryCheck::FLAG,
                'rules' => (object) [],
            ], JSON_THROW_ON_ERROR));

            return 'Request refused.';
        }

        $handler();

        return 'ok';
    }

    private function recordLines(): void
    {
        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->lines[] = $line;
        });
    }
}
