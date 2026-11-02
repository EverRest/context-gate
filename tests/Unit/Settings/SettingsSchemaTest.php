<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Settings\SettingsSchema;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** spec 007 T010 (FR-004, FR-006, FR-009, data-model Setting keys): allowed keys, types and forbidden keys. */
class SettingsSchemaTest extends TestCase
{
    /**
     * @param array<string, mixed> $document
     * @return list<string> the keys the schema reports, in the order they were found
     */
    private function keysOf(array $document): array
    {
        return array_column(SettingsSchema::violations($document), 'key');
    }

    public function test_a_document_with_every_setting_key_at_a_valid_value_has_no_violations(): void
    {
        $this->assertSame([], SettingsSchema::violations([
            'enabled' => true,
            'profile' => 'balanced',
            'roles' => ['alice' => ['tools' => [], 'ceiling' => 'internal']],
            'stdio_roles' => ['alice' => 'alice'],
            'log_level' => 'warning',
            'firewall' => ['ceiling' => 'internal', 'limits' => [
                'per_call_bytes' => 1024, 'per_session_bytes' => null, 'per_day_bytes' => 2048, 'calls_per_minute' => 10,
            ]],
            'sources' => ['openapi' => [
                'paths' => [], 'allowlist' => [], 'examples' => 'drop', 'access' => 'read',
                'timeout_seconds' => 2.5, 'max_bytes' => 1024,
            ]],
        ]));
    }

    public function test_an_unknown_key_is_reported_by_its_full_name(): void
    {
        $violations = SettingsSchema::violations(['firewall' => ['limits' => ['per_call_byte' => 1]]]);

        $this->assertSame(['firewall.limits.per_call_byte'], array_column($violations, 'key'));
    }

    public function test_redaction_is_reported_with_the_message_that_it_cannot_be_disabled(): void
    {
        $violations = SettingsSchema::violations(['redaction' => false]);

        $this->assertSame(['redaction'], array_column($violations, 'key'));
        $this->assertStringContainsString('cannot be disabled', $violations[0]['message']);
    }

    public function test_a_key_naming_a_stage_is_reported(): void
    {
        $this->assertSame(['stages'], $this->keysOf(['stages' => ['budget', 'redact']]));
        $this->assertSame(['pipeline'], $this->keysOf(['pipeline' => []]));
    }

    public function test_connector_access_other_than_read_is_reported(): void
    {
        $this->assertSame(['sources.openapi.access'], $this->keysOf(['sources' => ['openapi' => ['access' => 'write']]]));
    }

    public function test_log_level_accepts_the_five_levels_and_rejects_off(): void
    {
        foreach (['debug', 'info', 'notice', 'warning', 'error'] as $level) {
            $this->assertSame([], SettingsSchema::violations(['log_level' => $level]), $level);
        }

        $this->assertSame(['log_level'], $this->keysOf(['log_level' => 'off']));
    }

    public function test_an_unknown_profile_is_reported(): void
    {
        $this->assertSame(['profile'], $this->keysOf(['profile' => 'permissive']));
    }

    public function test_an_unset_profile_is_valid_and_means_the_default(): void
    {
        $this->assertSame([], SettingsSchema::violations(['profile' => null]));
    }

    public function test_a_limit_of_the_wrong_type_is_reported(): void
    {
        $this->assertSame(['firewall.limits.per_call_bytes'], $this->keysOf(['firewall' => ['limits' => ['per_call_bytes' => '1024']]]));
    }

    public function test_a_valid_tool_entry_has_no_violations(): void
    {
        $entry = ['enabled' => true, 'class' => 'A\\B', 'gate' => 'x.y', 'fingerprint' => 'abc', 'contract' => 'C\\D'];

        $this->assertSame([], SettingsSchema::violations(['tools' => ['probe' => $entry]]));
    }

    public function test_an_unknown_tool_entry_key_is_a_violation(): void
    {
        $violations = SettingsSchema::violations(['tools' => ['probe' => ['enabled' => true, 'max_bytes' => 10]]]);

        $this->assertSame(['tools.probe.max_bytes'], array_column($violations, 'key'));
    }

    #[DataProvider('wrongTypes')]
    public function test_a_wrong_type_in_a_tool_entry_is_a_violation(string $key, mixed $value): void
    {
        $violations = SettingsSchema::violations(['tools' => ['probe' => [$key => $value]]]);

        $this->assertSame(["tools.probe.{$key}"], array_column($violations, 'key'));
    }

    /** @return array<string, array{string, mixed}> */
    public static function wrongTypes(): array
    {
        return ['enabled' => ['enabled', 'yes'], 'class' => ['class', 5], 'gate' => ['gate', []], 'fingerprint' => ['fingerprint', 1], 'contract' => ['contract', true]];
    }

    public function test_a_tool_entry_that_is_not_a_section_is_a_violation(): void
    {
        $this->assertSame(['tools.probe'], array_column(SettingsSchema::violations(['tools' => ['probe' => true]]), 'key'));
    }
}
