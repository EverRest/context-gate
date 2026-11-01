<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Settings\ConfigExporter;
use ContextGate\Settings\SettingsValidator;
use PHPUnit\Framework\TestCase;

/** spec 007 T036 (US6, FR-012, R7): export carries no canary value, no fingerprint and no secret-named scalar. */
class ConfigExporterTest extends TestCase
{
    private const SENTINEL = 'CANARY_FAKE_FIXTURE_ONLY_7f3a9c';

    public function test_export_omits_canary_values_and_their_hash(): void
    {
        $document = $this->document();

        $json = json_encode(ConfigExporter::export($document), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::SENTINEL, $json);
        $this->assertStringNotContainsString(hash('sha256', self::SENTINEL), $json);
        $this->assertArrayNotHasKey('canaries', ConfigExporter::export($document));
    }

    public function test_export_drops_scalar_keys_named_like_secrets(): void
    {
        $exported = ConfigExporter::export($this->document());

        $this->assertArrayNotHasKey('api_token', $exported);
        $this->assertArrayNotHasKey('password', $exported);
        $this->assertSame('strict', $exported['profile']);
    }

    public function test_export_keeps_structure_and_passes_the_validator(): void
    {
        $exported = ConfigExporter::export($this->document());

        $this->assertArrayHasKey('source_config_keys', $exported['classification']);
        $this->assertSame([], SettingsValidator::violations($exported, 'testing'));
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        return [
            'profile' => 'strict',
            'api_token' => 'FAKE_FIXTURE_ONLY_TOKEN',
            'password' => 'FAKE_FIXTURE_ONLY_PASSWORD',
            'canaries' => ['values' => [self::SENTINEL]],
            'classification' => ['source_config_keys' => ['key' => 'internal']],
            'firewall' => ['ceiling' => 'internal', 'limits' => ['per_call_bytes' => 16384]],
        ];
    }
}
