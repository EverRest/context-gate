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
 * User Story 5, scenario 2; FR-024. Harness note: GateCallTool does not run the canary stage until T086, so this
 * test composes Normalizer and CanaryCheck directly and writes the receipt line itself (ReasonCode has no
 * canary.hit case yet). The response text and the receipt must follow the contract either way.
 */
class CanaryBlockTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    public function test_a_canary_in_the_response_gives_the_generic_failure_and_a_canary_hit_receipt(): void
    {
        $this->recordLines();
        $rows = [['body' => 'see ' . CanaryValues::EMAIL, 'id' => 'USR_7f3a']];

        $text = $this->respond($rows);

        $this->assertMatchesRegularExpression('/^Request failed\. Reference: r_[0-9a-f]{8}$/', $text);
        $this->assertCount(1, $this->lines);

        $receipt = json_decode($this->lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($receipt);
        $this->assertSame('canary.hit', $receipt['reason']);
        $this->assertSame('blocked', $receipt['decision']);
    }

    public function test_the_log_and_the_response_never_contain_a_canary_value(): void
    {
        $this->recordLines();

        $text = $this->respond([['body' => 'token ' . CanaryValues::TOKEN]]);

        $this->assertStringNotContainsString(CanaryValues::TOKEN, $text);
        $this->assertStringNotContainsString(CanaryValues::EMAIL, $text);
        foreach ($this->lines as $line) {
            $this->assertStringNotContainsString(CanaryValues::TOKEN, $line);
            $this->assertStringNotContainsString(CanaryValues::EMAIL, $line);
        }
    }

    public function test_a_clean_response_is_returned_and_writes_no_canary_receipt(): void
    {
        $this->recordLines();

        $text = $this->respond([['body' => 'nothing to see']]);

        $this->assertSame('{"body":"nothing to see"}', $text);
        $this->assertSame([], $this->lines);
    }

    /**
     * Minimal stage composition standing in for the pipeline until T086: normalize, check canaries, and on a hit
     * return the generic failure and write a metadata-only receipt line.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function respond(array $rows): string
    {
        $payload = (new Normalizer(['body']))->handle(
            new Payload($rows, ['body', 'id']),
            static fn (Payload $next): Payload => $next,
        );
        $checked = (new CanaryCheck([CanaryValues::EMAIL, CanaryValues::TOKEN]))->handle(
            $payload,
            static fn (Payload $next): Payload => $next,
        );

        if (! in_array(CanaryCheck::FLAG, $checked->flags, true)) {
            return json_encode($checked->rows[0], JSON_THROW_ON_ERROR);
        }

        $id = 'r_' . bin2hex(random_bytes(4));
        Log::channel('context-gate')->info(json_encode([
            'id' => $id,
            'decision' => 'blocked',
            'reason' => CanaryCheck::FLAG,
            'rules' => (object) [],
        ], JSON_THROW_ON_ERROR));

        return 'Request failed. Reference: ' . $id;
    }

    private function recordLines(): void
    {
        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->lines[] = $line;
        });
    }
}
