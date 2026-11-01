<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Settings\ConfigVersionStore;
use ContextGate\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/** spec 007 T038 (US6, FR-014, data-model ConfigVersion): hash, index and previous rules on a temporary directory. */
class ConfigVersionStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/cg-versions-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    public function test_the_hash_does_not_depend_on_key_order(): void
    {
        $a = ['firewall' => ['ceiling' => 'internal', 'limits' => ['per_call_bytes' => 16384]], 'profile' => 'strict'];
        $b = ['profile' => 'strict', 'firewall' => ['limits' => ['per_call_bytes' => 16384], 'ceiling' => 'internal']];

        $this->assertSame(ConfigVersionStore::hashOf($a), ConfigVersionStore::hashOf($b));
        $this->assertSame(64, strlen(ConfigVersionStore::hashOf($a)));
    }

    public function test_the_same_document_gives_the_same_hash_and_no_second_index_entry(): void
    {
        $store = new ConfigVersionStore($this->dir);
        $document = ['profile' => 'strict'];

        $first = $store->save($document, 'cli');
        $second = $store->save($document, 'cli');

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->index()['versions']);
        $this->assertSame($first, $store->active());
    }

    public function test_each_entry_records_the_hash_active_before_it(): void
    {
        $store = new ConfigVersionStore($this->dir);

        $a = $store->save(['log_level' => 'info'], 'cli');
        $b = $store->save(['log_level' => 'notice'], 'cli');

        $this->assertNull($store->previous($a));
        $this->assertSame($a, $store->previous($b));
    }

    public function test_the_index_keeps_at_most_fifty_entries_and_the_newest_is_active(): void
    {
        $store = new ConfigVersionStore($this->dir);
        $last = '';

        for ($i = 0; $i < 55; $i++) {
            $last = $store->save(['firewall' => ['limits' => ['per_call_bytes' => $i + 1]]], 'cli');
        }

        $this->assertCount(50, $this->index()['versions']);
        $this->assertSame($last, $store->active());
    }

    public function test_an_unreadable_index_refuses_to_apply_and_is_not_overwritten(): void
    {
        mkdir($this->dir, 0700, true);
        file_put_contents($this->dir.'/index.json', '{not json');
        $store = new ConfigVersionStore($this->dir);

        $this->expectException(RuntimeException::class);

        try {
            $store->save(['profile' => 'strict'], 'cli');
        } finally {
            $this->assertSame('{not json', file_get_contents($this->dir.'/index.json'));
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function malformedIndexes(): iterable
    {
        $hash = str_repeat('a', 64);
        $entry = ['hash' => $hash, 'applied_at' => '2026-11-01T00:00:00Z', 'actor' => 'cli', 'previous' => null];

        yield 'active is an integer' => [['active' => 123, 'versions' => []]];
        yield 'active is an array' => [['active' => ['x'], 'versions' => []]];
        yield 'active is not a hash' => [['active' => 'not-a-hash', 'versions' => []]];
        yield 'active is null but versions hold an entry without a hash' => [['active' => null, 'versions' => ['a']]];
        yield 'entry is a string' => [['active' => $hash, 'versions' => ['a']]];
        yield 'entry hash is short' => [['active' => $hash, 'versions' => [['hash' => 'abc'] + $entry]]];
        yield 'entry hash is a path' => [['active' => $hash, 'versions' => [['hash' => '../../victim'] + $entry]]];
        yield 'entry actor is missing' => [['active' => $hash, 'versions' => [array_diff_key($entry, ['actor' => 1])]]];
        yield 'entry applied_at is an integer' => [['active' => $hash, 'versions' => [['applied_at' => 1] + $entry]]];
        yield 'entry previous is not a hash' => [['active' => $hash, 'versions' => [['previous' => '../x'] + $entry]]];
        yield 'versions is missing' => [['active' => $hash]];
        yield 'two entries share a hash' => [['active' => $hash, 'versions' => [$entry, ['applied_at' => '2026-11-02T00:00:00Z'] + $entry]]];
    }

    /** @param array<string, mixed> $index */
    #[DataProvider('malformedIndexes')]
    public function test_a_malformed_index_is_refused_and_never_used(array $index): void
    {
        mkdir($this->dir, 0700, true);
        file_put_contents($this->dir.'/index.json', json_encode($index, JSON_THROW_ON_ERROR));
        $store = new ConfigVersionStore($this->dir);

        $this->expectException(RuntimeException::class);

        try {
            $store->active();
        } finally {
            $this->assertSame(json_encode($index, JSON_THROW_ON_ERROR), file_get_contents($this->dir.'/index.json'));
        }
    }

    public function test_a_well_formed_index_is_read(): void
    {
        $hash = str_repeat('b', 64);
        mkdir($this->dir, 0700, true);
        file_put_contents($this->dir.'/index.json', json_encode(['active' => $hash, 'versions' => [['hash' => $hash, 'applied_at' => '2026-11-01T00:00:00Z', 'actor' => 'cli', 'previous' => null]]], JSON_THROW_ON_ERROR));

        $this->assertSame($hash, (new ConfigVersionStore($this->dir))->active());
    }

    public function test_a_tampered_hash_in_the_index_is_never_used_as_a_path(): void
    {
        $outside = $this->dir.'/victim.json';
        mkdir($this->dir.'/versions', 0700, true);
        file_put_contents($outside, 'keep me');
        $entry = ['hash' => '../victim', 'applied_at' => '2026-11-01T00:00:00Z', 'actor' => 'cli', 'previous' => null];
        file_put_contents($this->dir.'/index.json', json_encode(['active' => null, 'versions' => array_fill(0, 50, $entry)], JSON_THROW_ON_ERROR));
        $store = new ConfigVersionStore($this->dir);

        try {
            $store->save(['profile' => 'strict'], 'cli');
            $this->fail('a tampered index must be refused');
        } catch (RuntimeException) {
        }

        $this->assertSame('keep me', file_get_contents($outside));
    }

    public function test_a_re_applied_version_keeps_the_rollback_chain(): void
    {
        $store = new ConfigVersionStore($this->dir);

        $a = $store->save(['log_level' => 'info'], 'cli');
        $b = $store->save(['log_level' => 'notice'], 'cli');
        $store->save(['log_level' => 'info'], 'cli');

        $this->assertSame($a, $store->active());
        $this->assertSame($b, $store->previous($a));
        $this->assertSame([], array_values(array_diff([$a, $b], array_column($this->index()['versions'], 'hash'))));
    }

    public function test_a_failed_index_write_throws_instead_of_passing_silently(): void
    {
        mkdir($this->dir.'/index.json', 0700, true);
        file_put_contents($this->dir.'/index.json/keep', 'x');

        $this->expectException(RuntimeException::class);

        (new ConfigVersionStore($this->dir))->save(['profile' => 'strict'], 'cli');
    }

    public function test_a_failed_index_write_removes_the_version_file_it_created_and_keeps_the_active_version(): void
    {
        $store = new ConfigVersionStore($this->dir);
        $active = $store->save(['log_level' => 'info'], 'cli');

        // The config directory becomes read-only, so the index cannot be written; the versions directory stays writable,
        // so the new version file is written first and must be removed again.
        chmod($this->dir, 0500);

        try {
            $store->save(['log_level' => 'notice'], 'cli');
            $this->fail('a failed index write must throw');
        } catch (RuntimeException) {
        } finally {
            chmod($this->dir, 0700);
        }

        $this->assertSame($active, $store->active());
        $this->assertCount(1, glob($this->dir.'/versions/*.json') ?: []);
        $this->assertCount(1, $this->index()['versions']);
    }

    public function test_a_failed_index_write_keeps_the_version_file_of_an_entry_that_would_be_trimmed(): void
    {
        $store = new ConfigVersionStore($this->dir);
        $oldest = '';

        for ($i = 0; $i < 50; $i++) {
            $hash = $store->save(['firewall' => ['limits' => ['per_call_bytes' => $i + 1]]], 'cli');
            $oldest = $oldest === '' ? $hash : $oldest;
        }

        // The 51st version drops the oldest entry; the index write then fails, so the index must still list it and its file must stay.
        chmod($this->dir, 0500);

        try {
            $store->save(['firewall' => ['limits' => ['per_call_bytes' => 9999]]], 'cli');
            $this->fail('a failed index write must throw');
        } catch (RuntimeException) {
        } finally {
            chmod($this->dir, 0700);
        }

        $this->assertFileExists($this->dir.'/versions/'.$oldest.'.json');
        $this->assertContains($oldest, array_column($this->index()['versions'], 'hash'));
        $this->assertCount(50, $this->index()['versions']);
    }

    public function test_trim_keeps_the_rollback_target_and_the_previous_of_the_new_version(): void
    {
        $store = new ConfigVersionStore($this->dir);
        $hashes = [];

        for ($i = 0; $i < 50; $i++) {
            $hashes[] = $store->save(['firewall' => ['limits' => ['per_call_bytes' => $i + 1]]], 'cli');
        }

        // Rolling back to the oldest version makes it active; the next save must not drop it.
        $this->assertSame([], $store->rollback($hashes[0], 'development', static fn () => null));

        $saved = $store->save(['firewall' => ['limits' => ['per_call_bytes' => 9999]]], 'cli');

        $this->assertSame($hashes[0], $store->previous($saved));
        $this->assertFileExists($this->dir.'/versions/'.$hashes[0].'.json');
        $this->assertContains($hashes[0], array_column($this->index()['versions'], 'hash'));
    }

    public function test_the_dropped_oldest_version_file_is_removed_and_no_longer_listed(): void
    {
        $store = new ConfigVersionStore($this->dir);
        $oldest = '';

        for ($i = 0; $i < 50; $i++) {
            $hash = $store->save(['firewall' => ['limits' => ['per_call_bytes' => $i + 1]]], 'cli');
            $oldest = $oldest === '' ? $hash : $oldest;
        }

        $store->save(['firewall' => ['limits' => ['per_call_bytes' => 9999]]], 'cli');

        $this->assertFileDoesNotExist($this->dir.'/versions/'.$oldest.'.json');
        $this->assertNotContains($oldest, array_column($this->index()['versions'], 'hash'));
    }

    public function test_rollback_refuses_an_orphan_version_file_that_the_index_does_not_list(): void
    {
        $store = new ConfigVersionStore($this->dir);
        $active = $store->save(['log_level' => 'info'], 'cli');
        $orphan = hash('sha256', 'orphan');
        file_put_contents($this->dir.'/versions/'.$orphan.'.json', json_encode(['document' => ['log_level' => 'notice']], JSON_THROW_ON_ERROR));

        $violations = $store->rollback($orphan, 'development', static fn () => null);

        $this->assertSame(['hash'], array_column($violations, 'key'));
        $this->assertSame($active, $store->active());
    }

    public function test_path_refuses_any_value_that_is_not_a_sha256_hex_hash(): void
    {
        $path = new \ReflectionMethod(ConfigVersionStore::class, 'path');

        $this->expectException(InvalidArgumentException::class);

        $path->invoke(new ConfigVersionStore($this->dir), '../x');
    }

    /** @return array{active: ?string, versions: list<array<string, mixed>>} */
    private function index(): array
    {
        return json_decode((string) file_get_contents($this->dir.'/index.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}
