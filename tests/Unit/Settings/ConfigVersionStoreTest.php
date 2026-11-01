<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Settings\ConfigVersionStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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

    /** @return array{active: ?string, versions: list<array<string, mixed>>} */
    private function index(): array
    {
        return json_decode((string) file_get_contents($this->dir.'/index.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}
