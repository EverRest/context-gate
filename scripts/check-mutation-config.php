<?php

declare(strict_types=1);

use ColinODell\Json5\Json5Decoder;

/**
 * Fails when the mutation setup could lower the minimum score or exclude a mutant (spec 011 FR-002 to FR-006).
 *
 *   php scripts/check-mutation-config.php
 */
const MUTATION_FLOOR = 90;

/** Paths the security scope must contain (spec 011 FR-002). A directory ends with "/". */
const MUTATION_REQUIRED_SCOPE = [
    'src/Pipeline/',
    'src/Privacy/',
    'src/Http/',
    'src/Source/Fetch/',
    'src/Source/DocsPathPolicy.php',
    'src/Source/ExamplePolicy.php',
    'src/Source/SensitiveNames.php',
    'src/Server/PolicyStep.php',
    'src/Server/Admission.php',
    'src/Server/ArgumentsStep.php',
    'src/Policy/',
];

/**
 * Allow-list: any other top-level key is refused by name. Keys that drop code or void the score (source.excludes,
 * ignoreMsiWithNoMutations, mutators, ignore*) are not here. timeout and logs cannot change which mutants count.
 */
const MUTATION_ALLOWED_KEYS = ['source', 'minMsi', 'timeout', 'logs'];
const MUTATION_ALLOWED_SOURCE_KEYS = ['directories'];
const MUTATION_FORBIDDEN_FLAGS = ['--min-msi', '--min-covered-msi', '--ignore-msi-with-no-mutations'];

/** @return list<string> one line per violation, "path: message" or "path:line: message" */
function mutation_config_violations(string $root): array
{
    $out = [];
    $config = Json5Decoder::decode((string) file_get_contents($root.'/infection.json5'), true);
    $config = is_array($config) ? $config : [];
    foreach (array_keys($config) as $key) {
        if (!in_array($key, MUTATION_ALLOWED_KEYS, true)) {
            $out[] = "infection.json5: '{$key}' is not allowed (only ".implode(', ', MUTATION_ALLOWED_KEYS).' may be set)';
        }
    }
    $source = is_array($config['source'] ?? null) ? $config['source'] : [];
    foreach (array_keys($source) as $key) {
        if (!in_array($key, MUTATION_ALLOWED_SOURCE_KEYS, true)) {
            $out[] = "infection.json5: 'source.{$key}' is not allowed (a mutant may not be excluded)";
        }
    }
    if (($source['directories'] ?? null) !== ['src']) {
        $out[] = 'infection.json5: source.directories must be exactly ["src"]';
    }
    if (!isset($config['minMsi']) || !is_numeric($config['minMsi']) || (float) $config['minMsi'] < MUTATION_FLOOR) {
        $out[] = 'infection.json5: minMsi must be at least '.MUTATION_FLOOR;
    }

    $scope = require $root.'/scripts/mutation-scope.php';
    foreach (MUTATION_REQUIRED_SCOPE as $required) {
        if (!in_array($required, $scope, true)) {
            $out[] = "scripts/mutation-scope.php: required security path {$required} is missing";
        }
    }
    foreach ($scope as $path) {
        if (!is_dir($root.'/'.$path) && !is_file($root.'/'.$path)) {
            $out[] = "scripts/mutation-scope.php: {$path} does not exist";
        }
    }

    foreach (['composer.json', '.github/workflows/ci.yml'] as $file) {
        foreach (explode("\n", (string) file_get_contents($root.'/'.$file)) as $i => $line) {
            foreach (MUTATION_FORBIDDEN_FLAGS as $flag) {
                if (str_contains($line, $flag)) {
                    $out[] = $file.':'.($i + 1).": {$flag} is not allowed (the threshold lives in infection.json5)";
                }
            }
        }
    }

    foreach (mutation_php_files($root.'/src') as $file) {
        foreach (explode("\n", (string) file_get_contents($file)) as $i => $line) {
            if (str_contains($line, '@infection-ignore')) {
                $out[] = substr($file, strlen($root) + 1).':'.($i + 1).': @infection-ignore is not allowed';
            }
        }
    }

    return $out;
}

/** @return list<string> */
function mutation_php_files(string $dir): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f instanceof SplFileInfo && $f->isFile() && $f->getExtension() === 'php') {
            $files[] = $f->getPathname();
        }
    }
    sort($files);

    return $files;
}

if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    require_once dirname(__DIR__).'/vendor/autoload.php';
    $violations = mutation_config_violations(dirname(__DIR__));
    foreach ($violations as $v) {
        echo $v, "\n";
    }
    echo $violations === [] ? "mutation config ok\n" : count($violations)." mutation config violation(s)\n";
    exit($violations === [] ? 0 : 1);
}
