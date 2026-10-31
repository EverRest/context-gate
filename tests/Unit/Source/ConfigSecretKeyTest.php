<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Source\ConfigSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\TestCase;

/** FR-007: a config key that is itself a secret or an address is not shown. */
class ConfigSecretKeyTest extends TestCase
{
    public function test_an_array_key_that_looks_like_a_secret_or_an_email_is_masked(): void
    {
        config()->set('walk', ['api_keys' => [
            'FAKE_FIXTURE_ONLY_0123456789abcdef' => ['scope' => 'read'],
            'ada@example.invalid' => ['scope' => 'read'],
            'plain' => ['scope' => 'read'],
        ]]);

        $result = (new ConfigSource())->read(new SourceRequest(['prefix' => 'walk']));
        $text = (string) json_encode($result->data);

        $this->assertStringNotContainsString('FAKE_FIXTURE_ONLY', $text);
        $this->assertStringNotContainsString('ada@example.invalid', $text);
        $this->assertStringContainsString('walk.api_keys.<SECRET>.scope', $text);
        $this->assertStringContainsString('walk.api_keys.plain.scope', $text);
    }
}
