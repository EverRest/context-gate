<?php

declare(strict_types=1);

/**
 * The Infection argument list for the security scope. It holds no threshold and no mode flag: the minimum score lives
 * in infection.json5, and tests/Unit/Scripts/MutationCommandTest.php pins this exact list (spec 011 FR-004).
 *
 * @param list<string> $scope paths from scripts/mutation-scope.php
 *
 * @return list<string>
 */
function mutation_command(string $root, array $scope): array
{
    return [
        PHP_BINARY,
        $root.'/vendor/bin/infection',
        '--configuration='.$root.'/infection.json5',
        '--threads=max',
        '--show-mutations',
        '--no-progress',
        '--filter='.implode(',', $scope),
    ];
}
