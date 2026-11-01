<?php

declare(strict_types=1);

namespace ContextGate\Settings;

use RuntimeException;

/**
 * spec 007 US6, FR-014, research R1 and R3: applied versions live as files under the storage path, never in the PHP
 * config file. `index.json` holds the active hash and at most 50 entries; every write is a temp file renamed into
 * place. An unreadable index refuses the write and is left untouched.
 *
 * @internal May change without notice.
 */
final class ConfigVersionStore
{
    private const MAX_VERSIONS = 50;

    public function __construct(private readonly string $dir)
    {
    }

    public static function fromApp(): self
    {
        return new self(\storage_path('app/context-gate/config'));
    }

    /**
     * R3: sha256 over canonical JSON (keys sorted at every depth). Canary values never enter a hash (R7).
     *
     * @param array<string, mixed> $document
     */
    public static function hashOf(array $document): string
    {
        unset($document['canaries']);

        return hash('sha256', json_encode(self::sortKeys($document), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $document */
    public function save(array $document, string $actor): string
    {
        $hash = self::hashOf($document);
        $index = $this->index();
        $versions = array_values(array_filter($index['versions'], static fn (array $entry): bool => $entry['hash'] !== $hash));
        $existing = array_values(array_filter($index['versions'], static fn (array $entry): bool => $entry['hash'] === $hash));

        if ($existing === []) {
            $entry = ['hash' => $hash, 'applied_at' => gmdate('Y-m-d\TH:i:s\Z'), 'actor' => $actor, 'previous' => $index['active']];
            $this->writeFile($this->path($hash), json_encode($entry + ['document' => $document], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            $versions[] = $entry;
        } else {
            $versions[] = $existing[0];
        }

        $versions = $this->trim($versions, $hash);
        $this->writeIndex(['active' => $hash, 'versions' => $versions]);

        return $hash;
    }

    public function active(): ?string
    {
        return $this->index()['active'];
    }

    /** @return array<string, mixed>|null the stored document, or null when no such version file exists */
    public function find(string $hash): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1 || ! is_file($this->path($hash))) {
            return null;
        }

        $stored = json_decode((string) file_get_contents($this->path($hash)), true);

        return is_array($stored['document'] ?? null) ? $stored['document'] : null;
    }

    public function previous(string $hash): ?string
    {
        foreach ($this->index()['versions'] as $entry) {
            if ($entry['hash'] === $hash) {
                return $entry['previous'];
            }
        }

        return null;
    }

    /**
     * R3: the stored document is validated against the caps in force now. Nothing changes unless the list is empty.
     *
     * @return list<array{key: string, message: string}>
     */
    public function rollback(string $hash, string $env): array
    {
        $document = $this->find($hash);

        if ($document === null) {
            return [['key' => 'hash', 'message' => 'is not a stored version']];
        }

        $violations = SettingsValidator::violations($document, $env);

        if ($violations === []) {
            $index = $this->index();
            $index['active'] = $hash;
            $this->writeIndex($index);
        }

        return $violations;
    }

    /**
     * @return array{active: ?string, versions: list<array{hash: string, applied_at: string, actor: string, previous: ?string}>}
     */
    public function index(): array
    {
        if (! is_file($this->dir.'/index.json')) {
            return ['active' => null, 'versions' => []];
        }

        $index = json_decode((string) file_get_contents($this->dir.'/index.json'), true);

        if (! is_array($index) || ! array_key_exists('active', $index) || ! is_array($index['versions'] ?? null)) {
            throw new RuntimeException('The configuration index is unreadable; nothing was applied and the index was not changed.');
        }

        return ['active' => $index['active'], 'versions' => array_values($index['versions'])];
    }

    /**
     * Keeps at most 50 entries. The oldest go first, the one just applied never does, and each dropped version file
     * is removed with its entry.
     *
     * @param list<array<string, mixed>> $versions
     * @return list<array<string, mixed>>
     */
    private function trim(array $versions, string $active): array
    {
        $overflow = count($versions) - self::MAX_VERSIONS;

        foreach ($versions as $position => $entry) {
            if ($overflow <= 0) {
                break;
            }

            if ($entry['hash'] === $active) {
                continue;
            }

            @unlink($this->path((string) $entry['hash']));
            unset($versions[$position]);
            $overflow--;
        }

        return array_values($versions);
    }

    private function path(string $hash): string
    {
        return $this->dir.'/versions/'.$hash.'.json';
    }

    /** @param array{active: ?string, versions: list<array<string, mixed>>} $index */
    private function writeIndex(array $index): void
    {
        $this->writeFile($this->dir.'/index.json', json_encode($index, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    private function writeFile(string $path, string $contents): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        $temp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($temp, $contents);
        rename($temp, $path);
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
