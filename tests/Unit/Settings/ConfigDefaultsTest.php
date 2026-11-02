<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/** spec 007 T001: the shipped config carries the new keys at their defaults and no redaction or stage key. */
class ConfigDefaultsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function shipped(): array
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 3).'/config/context-gate.php';

        return $config;
    }

    public function test_the_new_keys_have_their_defaults(): void
    {
        $config = $this->shipped();

        $this->assertSame('strict', $config['profile']);
        $this->assertSame([], $config['roles']);
        $this->assertSame([], $config['stdio_roles']);
        $this->assertSame('info', $config['log_level']);
        $this->assertSame('read', $config['sources']['openapi']['access']);
        $this->assertNull($config['sources']['openapi']['timeout_seconds']);
        $this->assertNull($config['sources']['openapi']['max_bytes']);
        $this->assertNull($config['firewall']['limits']['per_call_bytes']);
    }

    /** spec 009 FR-008: the source tables are defined in code; the published file ships no source_* entry. */
    public function test_the_shipped_classification_holds_no_source_table(): void
    {
        $this->assertSame([], $this->shipped()['classification']);
    }

    public function test_there_is_no_redaction_key_and_no_stage_key(): void
    {
        $config = $this->shipped();

        $this->assertArrayNotHasKey('redaction', $config);
        $this->assertArrayNotHasKey('stages', $config);
        $this->assertArrayNotHasKey('pipeline', $config);
    }
}
