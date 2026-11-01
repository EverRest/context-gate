<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Settings\SettingsValidator;
use PHPUnit\Framework\TestCase;

/** spec 018 T005 (FR-019): a runbook tool entry takes only the spec 007 tool keys; no limit can be set per tool. */
class RunbookToolKeysTest extends TestCase
{
    /**
     * @param array<string, mixed> $entry
     * @return list<array{key: string, message: string}>
     */
    private function violationsOf(array $entry): array
    {
        return SettingsValidator::violations(['tools' => ['runbook.disk' => $entry]], 'testing');
    }

    public function test_the_spec_007_tool_keys_are_accepted(): void
    {
        $this->assertSame([], $this->violationsOf([
            'enabled' => true,
            'class' => 'ContextGate\\Source\\RunbookTool',
            'gate' => 'pass',
            'fingerprint' => 'abc',
            'contract' => 'none',
        ]));
    }

    public function test_a_max_bytes_key_under_a_runbook_tool_is_reported_as_invalid(): void
    {
        $violations = $this->violationsOf(['enabled' => true, 'max_bytes' => 512]);

        $this->assertSame(['tools.runbook.disk.max_bytes'], array_column($violations, 'key'));
    }
}
