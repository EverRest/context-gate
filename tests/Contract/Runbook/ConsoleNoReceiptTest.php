<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Runbook;

use ContextGate\Tests\Contract\GateCallHarness;
use ContextGate\Tests\Fixtures\Runbook\PassingRunbook;
use ContextGate\Tests\Fixtures\Runbook\ThrowingRunbook;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** spec 018 FR-013, FR-015, R3: a console run writes no receipt; an error step writes one operator note only. */
class ConsoleNoReceiptTest extends TestCase
{
    use GateCallHarness;

    public function test_a_disabled_tool_still_runs_and_writes_no_receipt(): void
    {
        config(['context-gate.runbooks' => ['passing' => PassingRunbook::class]]);
        config(['context-gate.tools' => []]);
        $this->recordLines();

        $this->assertSame(0, Artisan::call('gate:runbook', ['name' => 'passing']));
        $this->assertSame([], $this->lines);
    }

    public function test_an_error_step_writes_one_note_with_class_and_line_and_no_message(): void
    {
        config(['context-gate.runbooks' => ['throwing' => ThrowingRunbook::class]]);
        $this->recordLines();

        Artisan::call('gate:runbook', ['name' => 'throwing']);

        $this->assertCount(1, $this->lines);
        $note = json_decode($this->lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('decision', $note);
        $this->assertSame('RuntimeException', $note['exception']);
        $this->assertSame('ThrowingRunbook.php', $note['file']);
        $this->assertArrayHasKey('line', $note);
        $this->assertStringNotContainsString('SECRET_MESSAGE_NEVER_PRINTED', $this->lines[0]);
    }
}
