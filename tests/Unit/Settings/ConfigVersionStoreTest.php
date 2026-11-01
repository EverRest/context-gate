<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Settings\ConfigVersionStore;
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

    /** @return array{active: ?string, versions: list<array<string, mixed>>} */
    private function index(): array
    {
        return json_decode((string) file_get_contents($this->dir.'/index.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}
