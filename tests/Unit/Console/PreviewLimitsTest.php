<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\Contract\GateCallHarness;
use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** A preview runs against its own counters: it never uses up the caller's per-minute limit. */
class PreviewLimitsTest extends TestCase
{
    use GateCallHarness;

    public function test_previews_do_not_use_up_the_callers_per_minute_limit(): void
    {
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['id' => 'public', 'status' => 'public']]);
        config(['context-gate.firewall.limits.calls_per_minute' => 1]);
        ProbeTool::$output = (string) json_encode([['id' => 42, 'status' => 'paid']]);

        Artisan::call('gate:preview', ['tool' => 'probe', '--args' => '{"text":"x"}']);
        Artisan::call('gate:preview', ['tool' => 'probe', '--args' => '{"text":"x"}']);
        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertFalse($result['isError']);
    }
}
