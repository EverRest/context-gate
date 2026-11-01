<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Source\SensitiveNames;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** One masking rule for config keys and route segments (FR-007). */
class SecretSegmentRuleTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function secrets(): array
    {
        return [
            'base64 with plus' => ['dGVzdCt0b2tlbg+abc'],
            'base64 with slash-free padding' => ['dGVzdHRva2Vu=='],
            'short mixed token' => ['aB3dE9xK'],
            '12 char mixed token' => ['aB3dE9xKq2Zp'],
            '16 char letters and digits' => ['abcdefghijklmn01'],
            '19 char letters and digits' => ['abcdefghijklmno0123'],
            '20 hex' => ['0123456789abcdef0123'],
            'address' => ['bob@example.com'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function normal(): array
    {
        return [
            'users' => ['users'], 'param' => ['{user}'], 'optional param' => ['{user?}'], 'api' => ['api'],
            'v1' => ['v1'], 'hyphen' => ['order-items'], 'snake' => ['notification_channels'],
            'utf8mb4' => ['utf8mb4'], 'mixed param' => ['user-{id}'], 'int key' => ['404'],
        ];
    }

    #[DataProvider('secrets')]
    public function test_token_like_segments_are_secret_for_routes(string $segment): void
    {
        $this->assertTrue(SensitiveNames::looksSecret($segment));
    }

    #[DataProvider('secrets')]
    public function test_token_like_keys_are_secret_for_config(string $segment): void
    {
        $this->assertTrue(SensitiveNames::looksSecretKey($segment));
    }

    #[DataProvider('normal')]
    public function test_normal_segments_are_kept_for_routes(string $segment): void
    {
        $this->assertFalse(SensitiveNames::looksSecret($segment));
    }

    public function test_identifiers_are_kept_for_config(): void
    {
        foreach (['users', 'notification_channels', 'utf8mb4', 'v1', '404', 'abcdefghijklmno'] as $key) {
            $this->assertFalse(SensitiveNames::looksSecretKey($key), $key);
        }
        $this->assertTrue(SensitiveNames::looksSecretKey('order-items'));
    }
}
