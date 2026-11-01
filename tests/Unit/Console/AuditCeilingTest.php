<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\Fixtures\CustomerContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;

/** spec 007 FR-017 amendment 2026-11-01: gate:audit prints invalid for a ceiling no call can use, never personal or secret. */
class AuditCeilingTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function ceilings(): iterable
    {
        yield 'public' => ['public', 'ceiling: public'];
        yield 'internal' => ['internal', 'ceiling: internal'];
        yield 'personal' => ['personal', 'ceiling: invalid'];
        yield 'secret' => ['secret', 'ceiling: invalid'];
        yield 'bogus' => ['bogus', 'ceiling: invalid'];
    }

    #[DataProvider('ceilings')]
    public function test_the_ceiling_line_is_the_configured_class_only_when_a_call_can_use_it(string $configured, string $expected): void
    {
        config(['context-gate.classification' => ['customers' => ['customer' => 'internal', 'email' => 'internal']]]);
        config(['context-gate.tools' => ['probe' => ['enabled' => true, 'class' => ProbeTool::class, 'contract' => CustomerContract::class, 'risk' => 'low']]]);
        config(['context-gate.firewall.ceiling' => $configured]);

        $this->assertSame(0, Artisan::call('gate:audit'));
        $output = Artisan::output();

        $this->assertStringContainsString($expected, $output);
        $this->assertStringNotContainsString('ceiling: personal', $output);
        $this->assertStringNotContainsString('ceiling: secret', $output);
    }
}
