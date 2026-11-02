<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\Fixtures\CustomerContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * spec 007 FR-017 amendment 2026-11-01: gate:audit never prints personal or secret as a ceiling. Spec 009 FR-005: a
 * ceiling no call can use makes the settings invalid, and gate:audit reads only valid settings, so it names the key.
 */
class AuditCeilingTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function ceilings(): iterable
    {
        yield 'public' => ['public', 'ceiling: public'];
        yield 'internal' => ['internal', 'ceiling: internal'];
        yield 'personal' => ['personal', 'refused'];
        yield 'secret' => ['secret', 'refused'];
        yield 'bogus' => ['bogus', 'refused'];
    }

    #[DataProvider('ceilings')]
    public function test_the_ceiling_line_is_the_configured_class_only_when_a_call_can_use_it(string $configured, string $expected): void
    {
        config(['context-gate.classification' => ['customers' => ['customer' => 'internal', 'email' => 'internal']]]);
        config(['context-gate.tools' => ['probe' => ['enabled' => true, 'class' => ProbeTool::class, 'contract' => CustomerContract::class, 'risk' => 'low']]]);
        config(['context-gate.firewall.ceiling' => $configured]);

        $exit = Artisan::call('gate:audit');
        $output = Artisan::output();

        if (str_starts_with($expected, 'ceiling: ')) {
            $this->assertSame(0, $exit);
            $this->assertStringContainsString($expected, $output);
        } else {
            $this->assertSame(1, $exit);
        }

        $this->assertStringNotContainsString('ceiling: personal', $output);
        $this->assertStringNotContainsString('ceiling: secret', $output);
    }
}
