<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

/**
 * Builds a temporary application tree for the docs path policy. The denylist steps only run on files that
 * exist (realpath runs first, research R5), so the adversarial files are created here, not committed.
 */
final class DocsTree
{
    /** @return string real path of the application root, a child of a fresh temporary directory */
    public static function create(): string
    {
        $base = sys_get_temp_dir() . '/cg-docs-' . bin2hex(random_bytes(6));
        mkdir($base . '/app', 0777, true);
        $root = (string) realpath($base . '/app');

        self::put($base, 'outside.md', "outside the root\n");
        self::put($base, '.env', "APP_KEY=base64:FAKE_FIXTURE_ONLY\n");
        self::put($root, '.env', "APP_KEY=base64:FAKE_FIXTURE_ONLY\n");
        self::put($root, 'README.md', "# Readme\n");
        self::put($root, 'notes/plan.md', "# Plan\n");
        self::put($root, 'docs/guide.md', "Order status guide.\n");
        self::put($root, 'docs/.env', "APP_KEY=base64:FAKE_FIXTURE_ONLY\n");
        self::put($root, 'docs/.hidden.md', "# Hidden\n");
        self::put($root, 'docs/storage/file.md', "# Storage\n");
        self::put($root, 'docs/vendor/readme.md', "# Vendor\n");
        self::put($root, 'docs/node_modules/x.md', "# Modules\n");
        self::put($root, 'docs/keys/server.key', "FAKE_FIXTURE_ONLY\n");
        self::put($root, 'docs/certs/a.pem', "FAKE_FIXTURE_ONLY\n");
        self::put($root, 'docs/auth.json', "{}\n");
        self::put($root, 'docs/notes.exe', "binary\n");
        symlink('../.env', $root . '/docs/link-to-env');
        symlink('../../outside.md', $root . '/docs/link-outside');

        return $root;
    }

    /** Removes the temporary directory that holds the tree. Links are unlinked, never followed. */
    public static function remove(string $root): void
    {
        self::delete(dirname($root));
    }

    private static function put(string $dir, string $relative, string $content): void
    {
        $path = $dir . '/' . $relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    private static function delete(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::delete($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
