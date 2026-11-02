<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\CustomerContract;
use ContextGate\Tests\Fixtures\DayPseudonymCustomerContract;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\Fixtures\HiddenTool;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** US1 scenario 3, FR-003: --json is valid JSON and carries the same tools and classes as the text output. */
class AuditJsonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['context-gate.classification' => [
            'customers' => ['customer' => 'internal', 'email' => 'internal', 'name' => 'personal'],
        ]]);
        config(['context-gate.tools' => [
            'probe' => ['enabled' => true, 'class' => ProbeTool::class, 'contract' => CustomerContract::class, 'risk' => 'low'],
            'echo' => ['enabled' => true, 'class' => EchoTool::class, 'contract' => DayPseudonymCustomerContract::class, 'risk' => 'high'],
            'secret' => ['enabled' => false, 'class' => HiddenTool::class, 'contract' => CustomerContract::class],
        ]]);
    }

    public function test_json_parses_and_matches_the_text_output(): void
    {
        Artisan::call('gate:audit');
        $text = Artisan::output();

        $this->assertSame(0, Artisan::call('gate:audit', ['--json' => true]));
        $data = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['probe', 'echo'], array_column($data['tools'], 'tool'));
        $this->assertSame(['secret'], $data['not_enabled']);

        foreach ($data['tools'] as $tool) {
            $this->assertTrue($tool['enabled']);
            $this->assertStringContainsString($tool['tool']."\n", $text);
            $this->assertStringContainsString('risk: '.$tool['risk'], $text);
            $this->assertStringContainsString('ceiling: '.$tool['ceiling'], $text);
            $this->assertStringContainsString('contract: '.$tool['contract'], $text);
            foreach ($tool['field_classes'] as $class => $count) {
                $this->assertStringContainsString("{$class}={$count}", $text);
            }
            $this->assertSame(['tool', 'contract', 'classification'], array_keys($tool['rules']));
        }

        $this->assertSame(['internal' => 2], $data['tools'][0]['field_classes']);
        $this->assertSame(['personal' => 1], $data['tools'][1]['field_classes']);
    }

    public function test_disabled_tool_has_only_its_name_in_json(): void
    {
        Artisan::call('gate:audit', ['--json' => true]);
        $data = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['tools', 'not_enabled'], array_keys($data));
        $this->assertCount(2, $data['tools']);
    }
}
