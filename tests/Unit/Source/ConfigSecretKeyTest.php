<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Source\ConfigSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\TestCase;

/** FR-008, research R4 and R6: a secret-looking array key is masked in the key column, never shown. */
class ConfigSecretKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('walkcfg', [
            'api' => ['sk-live-FAKE_FIXTURE_ONLY_0123456789' => 'FAKE_FIXTURE_ONLY'],
            'db' => ['password' => 'FAKE_FIXTURE_ONLY'],
            'plain' => ['notification_channels' => 'FAKE_FIXTURE_ONLY'],
        ]);
    }

    public function test_a_token_like_array_key_is_masked_and_its_value_is_not_shown(): void
    {
        $result = (new ConfigSource())->read(new SourceRequest(['prefix' => 'walkcfg']));
        $this->assertTrue($result->ok);

        $this->assertStringNotContainsString('sk-live-FAKE_FIXTURE_ONLY_0123456789', (string) json_encode($result->data, JSON_THROW_ON_ERROR));
        $this->assertContains('walkcfg.api.<SECRET>', array_column($result->data['keys'], 'key'));
    }

    /** FR-008: an identifier named like a secret keeps its name; only its env name is hidden. */
    public function test_an_identifier_named_like_a_secret_keeps_its_name(): void
    {
        $result = (new ConfigSource())->read(new SourceRequest(['prefix' => 'walkcfg']));

        $this->assertContains('walkcfg.db.password', array_column($result->data['keys'], 'key'));
    }

    public function test_an_ordinary_config_identifier_is_kept(): void
    {
        $result = (new ConfigSource())->read(new SourceRequest(['prefix' => 'walkcfg']));

        $this->assertContains('walkcfg.plain.notification_channels', array_column($result->data['keys'], 'key'));
    }
}
