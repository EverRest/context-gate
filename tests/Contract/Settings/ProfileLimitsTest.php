<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Tests\Contract\GateCallHarness;
use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;

/**
 * spec 007 FR-017 (T031, research R2): the firewall budget that GateCallTool uses is the one the active profile selects.
 * Each test goes through tools/call, not through Budget directly.
 */
class ProfileLimitsTest extends TestCase
{
    use GateCallHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public', 'note' => 'internal', 'email' => 'personal']]);
        ProbeTool::$output = (string) json_encode([['id' => 1, 'status' => 'paid']]);
    }

    /** @return int the number of the first refused call, or -1 when none is refused within the limit */
    private function callsUntilRefused(int $limit): int
    {
        for ($i = 1; $i <= $limit + 1; $i++) {
            $result = $this->callTool('probe', ['text' => 'x']);

            if ($result['isError']) {
                return $i;
            }
        }

        return -1;
    }

    public function test_a_default_install_with_no_profile_allows_strict_calls_per_minute(): void
    {
        config(['context-gate.profile' => null]);

        $this->assertSame(61, $this->callsUntilRefused(60), 'strict allows 60 calls a minute; the 61st is rate.limited');
    }

    public function test_balanced_allows_the_code_cap_calls_per_minute(): void
    {
        config(['context-gate.profile' => 'balanced']);

        $this->assertSame(301, $this->callsUntilRefused(300), 'balanced allows 300 calls a minute; the 301st is rate.limited');
    }

    public function test_a_default_install_truncates_a_call_at_the_strict_per_call_limit(): void
    {
        config(['context-gate.profile' => null]);
        ProbeTool::$output = (string) json_encode(array_fill(0, 20, ['id' => 1, 'status' => str_repeat('p', 1000)]));

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertTrue($result['structuredContent']['truncated'], 'about 20 KB is over the strict 16384-byte per-call limit');
    }

    public function test_balanced_does_not_truncate_the_same_call(): void
    {
        config(['context-gate.profile' => 'balanced']);
        ProbeTool::$output = (string) json_encode(array_fill(0, 20, ['id' => 1, 'status' => str_repeat('p', 1000)]));

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertFalse($result['structuredContent']['truncated'], 'about 20 KB is within the balanced 65536-byte per-call cap');
    }
}
