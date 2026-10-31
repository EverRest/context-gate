<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Source\SensitiveNames;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SensitiveNamesTest extends TestCase
{
    public function test_a_dotted_config_key_with_a_sensitive_segment_matches(): void
    {
        $this->assertTrue(SensitiveNames::matches('database.connections.mysql.password'));
    }

    public function test_a_plain_environment_name_does_not_match(): void
    {
        $this->assertFalse(SensitiveNames::matches('DB_HOST'));
    }

    public function test_a_secret_suffix_in_an_environment_name_matches(): void
    {
        $this->assertTrue(SensitiveNames::matches('STRIPE_SECRET'));
    }

    public function test_any_name_that_matches_is_enough(): void
    {
        $this->assertTrue(SensitiveNames::matches('DB_HOST', 'DB_TOKEN'));
        $this->assertFalse(SensitiveNames::matches('DB_HOST', 'APP_NAME'));
    }

    #[DataProvider('words')]
    public function test_each_sensitive_word_matches_in_any_case(string $word): void
    {
        $this->assertTrue(SensitiveNames::matches(strtolower($word)));
        $this->assertTrue(SensitiveNames::matches(strtoupper($word)));
        $this->assertTrue(SensitiveNames::matches('x_' . ucfirst($word) . '_y'));
    }

    /** @return list<array{string}> */
    public static function words(): array
    {
        return array_map(
            static fn (string $word): array => [$word],
            ['secret', 'key', 'token', 'password', 'dsn', 'private', 'credentials'],
        );
    }
}
