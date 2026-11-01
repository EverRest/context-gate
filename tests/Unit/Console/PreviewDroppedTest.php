<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\Contract\GateCallHarness;
use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** T041, User Story 4 scenario 2: the Dropped section lists each dropped field with its reason code. */
class PreviewDroppedTest extends TestCase
{
    use GateCallHarness;

    public function test_dropped_section_lists_the_unclassified_field_and_its_reason(): void
    {
        $this->recordLines();
        // "note" has no class in the catalog, so the projection drops it.
        $this->configureTool(ProbeContract::class, ['customers' => ['id' => 'public', 'status' => 'public']]);
        ProbeTool::$output = (string) json_encode([['id' => 42, 'status' => 'paid', 'note' => 'hello']]);

        Artisan::call('gate:preview', ['tool' => 'probe', '--args' => '{"text":"x"}']);

        $this->assertStringContainsString("Dropped:\n  note  field.unclassified", Artisan::output());
    }
}
