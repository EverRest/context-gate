<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Server\Receipt;
use ContextGate\Server\ReasonCode;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Log;

/** T052, spec 007 FR-015 and R5: the simulated field is written only when true. */
class ReceiptSimulatedTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    public function test_simulated_true_adds_the_field(): void
    {
        $this->recordLines();

        Receipt::write('alice', 'probe', ReasonCode::PolicyHidden, simulated: true);

        $this->assertTrue($this->record()['simulated']);
    }

    public function test_without_the_argument_there_is_no_simulated_key(): void
    {
        $this->recordLines();

        Receipt::write('alice', 'probe', ReasonCode::PolicyHidden);

        $this->assertArrayNotHasKey('simulated', $this->record());
    }

    public function test_simulated_false_is_never_written(): void
    {
        $this->recordLines();

        Receipt::write('alice', 'probe', ReasonCode::PolicyHidden, simulated: false);

        $this->assertArrayNotHasKey('simulated', $this->record());
    }

    private function recordLines(): void
    {
        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->lines[] = $line;
        });
    }

    /** @return array<string, mixed> */
    private function record(): array
    {
        return json_decode($this->lines[0], true, 512, JSON_THROW_ON_ERROR);
    }
}
