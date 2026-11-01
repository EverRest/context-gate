<?php

declare(strict_types=1);

/**
 * Runs Infection over the security scope (scripts/mutation-scope.php) and forwards its exit code. The minimum score
 * is read from infection.json5 by Infection itself; this script adds no threshold of its own (spec 011 FR-004).
 *
 *   php scripts/mutation.php
 */
$root = dirname(__DIR__);
$scope = require $root.'/scripts/mutation-scope.php';
$command = [
    PHP_BINARY,
    $root.'/vendor/bin/infection',
    '--configuration='.$root.'/infection.json5',
    '--threads=max',
    '--show-mutations',
    '--no-progress',
    '--filter='.implode(',', $scope),
];
$escaped = implode(' ', array_map(escapeshellarg(...), $command));
passthru($escaped, $code);
exit($code);
