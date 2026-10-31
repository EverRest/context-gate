<?php

declare(strict_types=1);

/**
 * Fails when a file under src/ is longer than 250 lines or a file under tests/ longer than 400 lines
 * (comments included), or when either is larger than 40 KB.
 *
 *   php scripts/check-file-length.php
 */
const FILE_LENGTH_MAX_LINES = ['src' => 250, 'tests' => 400];
const FILE_LENGTH_MAX_BYTES = 40960;

/** @return list<array{file: string, lines: int, bytes: int, max: int}> files under $root/src and $root/tests over either limit */
function file_length_violations(string $root): array
{
    $out = [];
    foreach (FILE_LENGTH_MAX_LINES as $dir => $max) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f instanceof SplFileInfo || !$f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            $lines = count(file($f->getPathname()) ?: []);
            $bytes = $f->getSize();
            if ($lines > $max || $bytes > FILE_LENGTH_MAX_BYTES) {
                $out[] = ['file' => $f->getPathname(), 'lines' => $lines, 'bytes' => $bytes, 'max' => $max];
            }
        }
    }
    usort($out, static fn (array $a, array $b): int => strcmp($a['file'], $b['file']));

    return $out;
}

if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $root = dirname(__DIR__);
    $violations = file_length_violations($root);
    foreach ($violations as $v) {
        $rel = substr($v['file'], strlen($root) + 1);
        printf("%s: %d lines, %d bytes (limit %d lines, %d bytes)\n", $rel, $v['lines'], $v['bytes'], $v['max'], FILE_LENGTH_MAX_BYTES);
    }
    echo count($violations) === 0 ? "file length ok\n" : count($violations) . " file(s) over the limit\n";
    exit($violations === [] ? 0 : 1);
}
