<?php

declare(strict_types=1);

/**
 * Fails when line coverage of src/ in a clover report is below COVERAGE_FLOOR, or when no coverage was measured
 * (spec 011 FR-004, FR-007). The floor is defined here, not in the CI job.
 *
 *   php scripts/coverage-floor.php build/coverage/clover.xml
 */
const COVERAGE_FLOOR = 92.0;

/**
 * A src file absent from the report fails the check: an excluded file would otherwise shrink the denominator.
 *
 * @param string $clover path to a clover report
 * @param string|null $summaryFile file that receives the report line (the GitHub job summary), if any
 * @param string|null $srcRoot the src directory every file must appear under (default: this repository's src/)
 *
 * @return array{0: bool, 1: string} whether the floor is met, and the one-line report
 */
function coverage_floor_check(string $clover, ?string $summaryFile = null, ?string $srcRoot = null): array
{
    $xml = is_file($clover) ? simplexml_load_file($clover) : false;
    $statements = 0;
    $covered = 0;
    $measured = [];
    if ($xml !== false) {
        foreach ($xml->xpath('//file[contains(@name, "/src/")]') ?: [] as $file) {
            $measured[] = (string) $file['name'];
            $statements += (int) $file->metrics['statements'];
            $covered += (int) $file->metrics['coveredstatements'];
        }
    }
    $missing = [];
    foreach (coverage_src_files($srcRoot ?? dirname(__DIR__).'/src') as $rel) {
        if (!array_filter($measured, static fn (string $name): bool => str_ends_with($name, '/src/'.$rel))) {
            $missing[] = 'src/'.$rel;
        }
    }

    if ($statements === 0) {
        $line = "no coverage measured in {$clover} (floor ".(int) COVERAGE_FLOOR."%)";
        $ok = false;
    } elseif ($missing !== []) {
        $line = 'not in the coverage report: '.implode(', ', $missing).' (every src file must be measured)';
        $ok = false;
    } else {
        $percent = floor($covered * 1000 / $statements) / 10;
        $ok = $percent >= COVERAGE_FLOOR;
        $line = sprintf('line coverage %.1f%% (floor %s%%)', $percent, (int) COVERAGE_FLOOR);
        $line .= $ok ? '' : ': below the floor';
    }

    if ($summaryFile !== null) {
        file_put_contents($summaryFile, $line."\n", FILE_APPEND);
    }

    return [$ok, $line];
}

/** @return list<string> the src/ PHP files under $srcRoot, relative to it */
function coverage_src_files(string $srcRoot): array
{
    if (!is_dir($srcRoot)) {
        return [];
    }
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f instanceof SplFileInfo && $f->isFile() && $f->getExtension() === 'php') {
            $files[] = substr($f->getPathname(), strlen($srcRoot) + 1);
        }
    }
    sort($files);

    return $files;
}

if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    [$ok, $line] = coverage_floor_check($argv[1] ?? 'build/coverage/clover.xml', getenv('GITHUB_STEP_SUMMARY') ?: null);
    echo $line, "\n";
    exit($ok ? 0 : 1);
}
