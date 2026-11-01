<?php

declare(strict_types=1);

/**
 * Fails when a comment under src/ holds a spec, requirement, success-criterion, task, research or decision ID
 * (spec 011 FR-015, FR-016). The shipped package has no specs/ directory, so such an ID points nowhere. Only comment
 * tokens are read; tests and specs are not checked.
 *
 *   php scripts/check-spec-refs.php
 */
const SPEC_REF_PATTERN = '/\b[Ss]pec\s+\d{3}\b|\b(?:FR|SC|NFR)-\d{3}\b|\bUS\d+\b|\bT\d{3}\b|\bresearch\s+R\d+\b|\bR\d{1,2}\b|\bD\d{2}\b/';

/** @return list<string> "src/path.php:line: matched text" for each ID in a comment */
function spec_ref_violations(string $root): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS));
    $files = [];
    foreach ($it as $f) {
        if ($f instanceof SplFileInfo && $f->isFile() && $f->getExtension() === 'php') {
            $files[] = $f->getPathname();
        }
    }
    sort($files);

    foreach ($files as $file) {
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (preg_match_all(SPEC_REF_PATTERN, $token[1], $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($matches[0] as [$text, $offset]) {
                $line = $token[2] + substr_count($token[1], "\n", 0, $offset);
                $out[] = substr($file, strlen($root) + 1).":{$line}: {$text}";
            }
        }
    }

    return $out;
}

if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $violations = spec_ref_violations(dirname(__DIR__));
    foreach ($violations as $v) {
        echo $v, "\n";
    }
    echo $violations === [] ? "spec references ok\n" : count($violations)." spec reference(s) in comments\n";
    exit($violations === [] ? 0 : 1);
}
