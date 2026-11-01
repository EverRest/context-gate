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

    public function test_dropped_section_lists_an_unlisted_field_and_its_reason_without_its_value(): void
    {
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['id' => 'public', 'status' => 'public', 'note' => 'internal', 'email' => 'personal']]);
        // "user_id" is a column the tool returns but the contract does not list.
        ProbeTool::$output = (string) json_encode([['id' => 1, 'status' => 'paid', 'user_id' => 'u-9913']]);

        Artisan::call('gate:preview', ['tool' => 'probe', '--args' => '{"text":"x"}']);

        $this->assertStringContainsString("  user_id  field.unlisted\n", Artisan::output());
        $this->assertStringNotContainsString('u-9913', Artisan::output());
    }

    public function test_a_contract_field_without_a_class_refuses_the_call_instead_of_dropping_it(): void
    {
        $this->recordLines();
        // "note" has no class in the catalog: the contract breaks a load rule, so the call is refused per call.
        $this->configureTool(ProbeContract::class, ['customers' => ['id' => 'public', 'status' => 'public']]);
        ProbeTool::$output = (string) json_encode([['id' => 42, 'status' => 'paid', 'note' => 'hello']]);

        Artisan::call('gate:preview', ['tool' => 'probe', '--args' => '{"text":"x"}']);

        $this->assertStringContainsString('Refused: config.invalid.', Artisan::output());
        $this->assertStringNotContainsString('hello', Artisan::output());
    }
}
