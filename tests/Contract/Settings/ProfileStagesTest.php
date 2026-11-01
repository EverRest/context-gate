<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Server\FirewallRunner;
use ContextGate\Tests\Contract\GateCallHarness;
use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;

/** spec 007 T022 (US2, FR-004, characterization of spec 002): the stage list is the same under both profiles. */
class ProfileStagesTest extends TestCase
{
    use GateCallHarness;

    /** @return list<string> the stages of one allowed call, in order */
    private function stagesUnder(string $profile): array
    {
        /** @var list<string> $stages */
        $stages = [];
        app()->instance(FirewallRunner::class, new FirewallRunner(static function (string $name) use (&$stages): void {
            $stages[] = $name;
        }));
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public', 'note' => 'internal', 'email' => 'personal']]);
        ProbeTool::$output = (string) json_encode([['id' => 42, 'status' => 'paid', 'note' => 'hello', 'email' => 'a@example.invalid']]);
        config(['context-gate.profile' => $profile]);

        $this->callTool('probe', ['text' => 'x']);

        return $stages;
    }

    public function test_the_stage_list_is_identical_under_strict_and_balanced(): void
    {
        $strict = $this->stagesUnder('strict');
        $balanced = $this->stagesUnder('balanced');

        $this->assertNotEmpty($strict);
        $this->assertSame($strict, $balanced);
    }
}
