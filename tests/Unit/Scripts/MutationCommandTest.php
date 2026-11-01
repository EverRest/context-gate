<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/scripts/mutation-command.php';

/**
 * Spec 011 US1, FR-004: the Infection command passes no threshold, scope or mode of its own. Any extra flag (a narrower
 * filter, --only-covered, --git-diff-lines, --min-msi) changes the argument list and fails here.
 */
class MutationCommandTest extends TestCase
{
    public function test_the_command_passes_exactly_the_gate_arguments(): void
    {
        $root = dirname(__DIR__, 3);

        $this->assertSame([
            PHP_BINARY,
            $root.'/vendor/bin/infection',
            '--configuration='.$root.'/infection.json5',
            '--threads=max',
            '--show-mutations',
            '--no-progress',
            '--filter=src/Pipeline/,src/Policy/',
        ], mutation_command($root, ['src/Pipeline/', 'src/Policy/']));
    }

    public function test_the_real_security_scope_is_the_whole_filter(): void
    {
        $root = dirname(__DIR__, 3);
        $scope = require $root.'/scripts/mutation-scope.php';
        $command = mutation_command($root, $scope);

        $this->assertSame('--filter='.implode(',', $scope), end($command));
        $this->assertCount(7, $command);
    }
}
