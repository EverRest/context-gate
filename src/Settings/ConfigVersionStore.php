<?php

declare(strict_types=1);

namespace ContextGate\Settings;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

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
        return ConfigLock::run($this->dir, function () use ($document, $actor): string {
            $hash = self::hashOf($document);
            $index = $this->index();
            $versions = array_values(array_filter($index['versions'], static fn (array $entry): bool => $entry['hash'] !== $hash));
            $existing = array_values(array_filter($index['versions'], static fn (array $entry): bool => $entry['hash'] === $hash));

            $created = null;

            if ($existing === []) {
                $entry = ['hash' => $hash, 'applied_at' => gmdate('Y-m-d\TH:i:s\Z'), 'actor' => $actor, 'previous' => $index['active']];
                $created = $this->path($hash);
                $this->writeFile($created, json_encode($entry + ['document' => $document], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
                $versions[] = $entry;
            } else {
                // spec 007 R3: a re-applied version moves to the newest position and its previous becomes the version
                // active just before it, so A -> B -> A rolls back to B. Re-applying the active version changes nothing.
                $entry = $existing[0];
                $entry['previous'] = $hash === $index['active'] ? $entry['previous'] : $index['active'];
                $versions[] = $entry;
            }

            $trimmed = $this->trim($versions, [$hash, $entry['previous']]);

            try {
                $this->writeIndex(['active' => $hash, 'versions' => $trimmed['kept']]);
            } catch (Throwable $error) {
                // spec 007 F5: a version file this call created does not outlive the failed index write.
                if ($created !== null) {
                    @unlink($created);
                }

                throw $error;
            }

            // spec 007 F7: a dropped version file is removed only after the index no longer lists it.
            foreach ($trimmed['dropped'] as $droppedHash) {
                @unlink($this->path($droppedHash));
            }

            return $hash;
        });
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
     * `$audit` runs after validation and before the index changes; when it throws, nothing is applied (spec 007 F6).
     *
     * @param Closure(): void $audit
     * @return list<array{key: string, message: string}>
     */
    public function rollback(string $hash, string $env, Closure $audit): array
    {
        return ConfigLock::run($this->dir, function () use ($hash, $env, $audit): array {
            if (! in_array($hash, array_column($this->index()['versions'], 'hash'), true)) {
                return [['key' => 'hash', 'message' => 'is not a listed version']];
            }

            $document = $this->find($hash);

            if ($document === null) {
                return [['key' => 'hash', 'message' => 'is not a stored version']];
            }

            $violations = ConfigOverlay::mergedViolations($document, $env);

            if ($violations === []) {
                $audit();
                $index = $this->index();
                $index['active'] = $hash;
                $this->writeIndex($index);
            }

            return $violations;
        });
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

        if (! ConfigIndexShape::wellFormed($index)) {
            throw new RuntimeException('The configuration index is unreadable; nothing was applied and the index was not changed.');
        }

        return ['active' => $index['active'], 'versions' => array_values($index['versions'])];
    }

    /**
     * Keeps at most 50 entries. The oldest go first; the active version and the previous of the version being saved
     * never do, so a rollback target stays on disk. The dropped hashes are returned, not deleted: the caller removes
     * their version files only after the index write succeeds.
     *
     * @param list<array<string, mixed>> $versions
     * @param list<?string> $protected
     * @return array{kept: list<array<string, mixed>>, dropped: list<string>}
     */
    private function trim(array $versions, array $protected): array
    {
        $overflow = count($versions) - self::MAX_VERSIONS;
        $dropped = [];

        foreach ($versions as $position => $entry) {
            if ($overflow <= 0) {
                break;
            }

            if (in_array($entry['hash'], $protected, true)) {
                continue;
            }

            $dropped[] = (string) $entry['hash'];
            unset($versions[$position]);
            $overflow--;
        }

        return ['kept' => array_values($versions), 'dropped' => $dropped];
    }

    private function path(string $hash): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            throw new InvalidArgumentException('A version hash is 64 lowercase hex characters; no other value names a file.');
        }

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

        if (@file_put_contents($temp, $contents) === false || ! @rename($temp, $path)) {
            @unlink($temp);

            throw new RuntimeException('The configuration could not be written; nothing was applied.');
        }
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
