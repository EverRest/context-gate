<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\CanaryValues;
use ContextGate\Tests\Fixtures\CustomerContract;
use ContextGate\Tests\Fixtures\DayPseudonymCustomerContract;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\Fixtures\SeededPii;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** FR-004: no canary and no seeded personal value appears in the audit, in text or JSON. */
class AuditNoValueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
        });
        DB::table('customers')->insert(['id' => 1, 'name' => SeededPii::EMAIL, 'email' => SeededPii::EMAIL]);

        config(['context-gate.canaries.values' => [CanaryValues::TOKEN]]);
        config(['context-gate.classification' => [
            'customers' => ['customer' => 'internal', 'email' => 'internal', 'name' => 'personal'],
        ]]);
        config(['context-gate.tools' => [
            'probe' => ['enabled' => true, 'class' => ProbeTool::class, 'contract' => CustomerContract::class, 'risk' => 'low'],
            'echo' => ['enabled' => true, 'class' => EchoTool::class, 'contract' => DayPseudonymCustomerContract::class, 'risk' => 'high'],
        ]]);
    }

    public function test_text_and_json_output_carry_no_canary_and_no_seeded_value(): void
    {
        Artisan::call('gate:audit');
        $text = Artisan::output();

        Artisan::call('gate:audit', ['--json' => true]);
        $json = Artisan::output();

        foreach ([$text, $json] as $output) {
            $this->assertStringNotContainsString(CanaryValues::TOKEN, $output);
            $this->assertStringNotContainsString(SeededPii::EMAIL, $output);
            $this->assertStringNotContainsString('CANARY', $output);
        }
    }
}
