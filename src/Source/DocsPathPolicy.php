<?php

declare(strict_types=1);

namespace ContextGate\Source;

/**
 * Decides whether a documentation path may be read (research R5). Steps run in order and the first failing
 * step is returned by name: realpath, root, denylist, allowlist, extension. Code only; no configuration reads
 * the denylist (FR-010).
 */
final class DocsPathPolicy
{
    /**
     * Matched case-insensitively against every segment of the real path. `name*` is a prefix, `*.ext` a
     * suffix, a trailing slash marks a folder name. Any dotfile or dot-folder is refused in addition.
     *
     * @var list<string>
     */
    public const DENYLIST = ['.env*', '*.key', '*.pem', 'auth.json', 'storage/', 'vendor/', '.git/', 'node_modules/'];

    /** @var list<string> */
    public const EXTENSIONS = ['md', 'txt', 'rst', 'yaml', 'yml', 'json'];

    /** @param list<string> $allowedPaths allowlisted roots relative to the application root, e.g. `docs/` */
    public function __construct(
        private string $root,
        private array $allowedPaths,
    ) {
    }

    /** @return string|null the first failing step name, or null when the path may be read */
    public function refusalStep(string $path): ?string
    {
        if (str_contains($path, "\0")) {
            return 'realpath';
        }
        $root = realpath($this->root);
        if ($root === false) {
            return 'root';
        }
        $real = realpath($root . '/' . ltrim($path, '/'));
        if ($real === false) {
            return 'realpath';
        }
        if (! str_starts_with($real, $root . '/')) {
            return 'root';
        }

        $relative = strtolower(substr($real, strlen($root) + 1));
        foreach (explode('/', $relative) as $segment) {
            if (self::denied($segment)) {
                return 'denylist';
            }
        }
        // A case-insensitive file system resolves `DOCS/` to `docs/`, so only there is the allowlist matched without case.
        $fold = is_dir(strtoupper($root)) && is_dir(strtolower($root));

        if (! self::isAllowed(substr($real, strlen($root) + 1), $this->allowedPaths, $fold)) {
            return 'allowlist';
        }
        $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        if (! in_array($extension, self::EXTENSIONS, true) || ! is_file($real)) {
            return 'extension';
        }

        return null;
    }

    private static function denied(string $segment): bool
    {
        if (str_starts_with($segment, '.')) {
            return true;
        }
        foreach (self::DENYLIST as $pattern) {
            $name = rtrim($pattern, '/');
            $hit = match (true) {
                str_starts_with($name, '*.') => str_ends_with($segment, substr($name, 1)),
                str_ends_with($name, '*') => str_starts_with($segment, substr($name, 0, -1)),
                default => $segment === $name,
            };
            if ($hit) {
                return true;
            }
        }

        return false;
    }

    /**
     * The allowlist is matched in the case given: on a case-sensitive file system `Docs/` is another folder than `docs/`.
     *
     * @param list<string> $allowedPaths
     */
    public static function isAllowed(string $relative, array $allowedPaths, bool $ignoreCase = false): bool
    {
        if ($ignoreCase) {
            $relative = strtolower($relative);
        }

        foreach ($allowedPaths as $entry) {
            $entry = $ignoreCase ? strtolower($entry) : $entry;
            if ($relative === $entry || (str_ends_with($entry, '/') && str_starts_with($relative, $entry))) {
                return true;
            }
        }

        return false;
    }
}
