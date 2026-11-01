<?php

declare(strict_types=1);

namespace ContextGate\Tests\EndToEnd;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** T055, US4 scenarios 1-2, SC-005: a fresh Laravel 12 and 13 skeleton installs and passes the three steps. */
class InstallMatrixTest extends TestCase
{
    /** @return list<array{string}> */
    public static function versions(): array
    {
        return [['12'], ['13']];
    }

    #[DataProvider('versions')]
    public function test_a_fresh_skeleton_installs_and_passes_the_three_steps(string $version): void
    {
        if (getenv('CONTEXT_GATE_INSTALL_TEST') !== '1') {
            $this->markTestSkipped('Set CONTEXT_GATE_INSTALL_TEST=1 to run the install matrix (needs network).');
        }

        $process = new Process(['bash', 'scripts/install-test.sh', $version], dirname(__DIR__, 2));
        $process->setTimeout(900)->run();

        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('install ok', $output);
        $this->assertStringContainsString('gate:check ok', $output);
        $this->assertStringContainsString('quickstart ok', $output);
    }
}
