<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** T040, User Story 4 scenario 1 and SC-004: the preview prints the response the caller would receive, byte for byte. */
class PreviewByteEqualTest extends TestCase
{
    use GateCallHarness;

    public function test_preview_text_equals_the_tool_response_byte_for_byte(): void
    {
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['id' => 'public', 'status' => 'public', 'email' => 'personal']]);
        ProbeTool::$output = (string) json_encode([['id' => 42, 'status' => 'paid', 'email' => 'jane@example.invalid']]);
        $served = $this->callTool('probe', ['text' => 'x'])['content'][0]['text'];

        Artisan::call('gate:preview', ['tool' => 'probe', '--args' => '{"text":"x"}']);

        $this->assertStringStartsWith($served."\n", Artisan::output());
    }
}
