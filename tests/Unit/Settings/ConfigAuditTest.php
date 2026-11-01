<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Settings\ConfigAudit;
use ContextGate\Settings\ConfigVersionStore;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Log;

/** spec 007 T040 (US6, FR-007, R7): audit lines hold key names and document hashes, never values. */
class ConfigAuditTest extends TestCase
{
    private const OLD_SENTINEL = 'SENTINEL_OLD_FAKE_FIXTURE_ONLY';

    private const NEW_SENTINEL = 'SENTINEL_NEW_FAKE_FIXTURE_ONLY';

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = sys_get_temp_dir().'/cg-audit-'.bin2hex(random_bytes(6)).'.log';
        config(['logging.channels.context-gate' => ['driver' => 'single', 'path' => $this->file]]);
        Log::forgetChannel('context-gate');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    public function test_an_applied_line_has_the_audit_fields(): void
    {
        ConfigAudit::record('config.applied', 'cli', ['profile' => 'strict'], ['profile' => 'strict', 'log_level' => 'notice']);

        $line = $this->decodedLine('config.applied');

        $this->assertSame('config.applied', $line['event']);
        $this->assertSame('cli', $line['actor']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $line['at']);
        $this->assertSame(['log_level'], $line['changed_keys']);
        $this->assertSame(ConfigVersionStore::hashOf(['profile' => 'strict']), $line['old_hash']);
        $this->assertSame(ConfigVersionStore::hashOf(['profile' => 'strict', 'log_level' => 'notice']), $line['new_hash']);
    }

    public function test_no_value_from_the_old_or_new_document_appears_in_the_line(): void
    {
        ConfigAudit::record(
            'config.applied',
            'cli',
            ['log_level' => self::OLD_SENTINEL],
            ['log_level' => self::NEW_SENTINEL],
        );

        $contents = (string) file_get_contents($this->file);

        $this->assertStringNotContainsString(self::OLD_SENTINEL, $contents);
        $this->assertStringNotContainsString(self::NEW_SENTINEL, $contents);
    }

    public function test_a_changed_canary_is_recorded_as_its_key_name_only(): void
    {
        ConfigAudit::record(
            'config.applied',
            'cli',
            ['canaries' => ['values' => [self::OLD_SENTINEL]]],
            ['canaries' => ['values' => [self::NEW_SENTINEL]]],
        );

        $line = $this->decodedLine('config.applied');
        $contents = (string) file_get_contents($this->file);

        $this->assertSame(['canaries.values'], $line['changed_keys']);
        $this->assertStringNotContainsString(self::OLD_SENTINEL, $contents);
        $this->assertStringNotContainsString(self::NEW_SENTINEL, $contents);
    }

    /** @return array<string, mixed> */
    private function decodedLine(string $event): array
    {
        foreach (file((string) $this->file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_contains($line, $event)) {
                return json_decode(substr($line, (int) strpos($line, '{')), true, 512, JSON_THROW_ON_ERROR);
            }
        }

        $this->fail("No {$event} line was written.");
    }
}
