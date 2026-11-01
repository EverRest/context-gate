<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** T057, US4 scenario 1-2: the install script has the shape the matrix relies on; no network is used here. */
class InstallScriptTest extends TestCase
{
    private const SCRIPT = 'scripts/install-test.sh';

    public function test_the_script_exists_and_starts_with_a_bash_shebang(): void
    {
        $path = dirname(__DIR__, 3).'/'.self::SCRIPT;

        $this->assertFileExists($path);
        $this->assertStringStartsWith('#!/usr/bin/env bash', (string) file_get_contents($path));
    }

    public function test_the_script_fails_fast_and_cleans_up_on_exit(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/'.self::SCRIPT);

        $this->assertStringContainsString('set -euo pipefail', $source);
        $this->assertMatchesRegularExpression('/^trap\s+.+\s+EXIT\s*$/m', $source);
    }

    public function test_the_script_refuses_a_missing_or_unknown_version_before_any_composer_call(): void
    {
        foreach ([[], ['11'], ['14'], ['12; rm -rf /']] as $args) {
            $process = new Process(['bash', self::SCRIPT, ...$args], dirname(__DIR__, 3));
            $process->setTimeout(30)->run();

            $this->assertNotSame(0, $process->getExitCode(), 'args: '.json_encode($args));
            $this->assertStringContainsString('usage', $process->getOutput().$process->getErrorOutput());
        }
    }
}
