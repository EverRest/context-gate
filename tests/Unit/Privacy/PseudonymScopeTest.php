<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Privacy;

use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

class PseudonymScopeTest extends TestCase
{
    public function test_session_key_is_held_in_memory_and_not_in_the_cache(): void
    {
        Cache::shouldReceive('get')->never();
        Cache::shouldReceive('put')->never();
        $scope = 'session:'.uniqid('', true);

        $first = (new Pseudonymizer())->pseudonym('cus', 'customers', '42', $scope);

        // A new instance still gives the same value: the key lives in the process, not in the object.
        $this->assertSame($first, (new Pseudonymizer())->pseudonym('cus', 'customers', '42', $scope));
    }

    public function test_day_scope_key_is_stored_encrypted_with_ttl_86400(): void
    {
        $this->assertCacheTtl('day', 86400);
    }

    public function test_week_scope_key_is_stored_encrypted_with_ttl_604800(): void
    {
        $this->assertCacheTtl('week', 604800);
    }

    public function test_a_scope_above_seven_days_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Pseudonymizer())->pseudonym('cus', 'customers', '42', 'month:lookup');
    }

    private function assertCacheTtl(string $kind, int $seconds): void
    {
        $stored = null;
        $ttl = null;

        Cache::shouldReceive('get')->andReturnNull();
        Cache::shouldReceive('put')->once()->andReturnUsing(
            function (string $key, string $value, int $expires) use (&$stored, &$ttl): bool {
                $stored = $value;
                $ttl = $expires;

                return true;
            },
        );

        (new Pseudonymizer())->pseudonym('cus', 'customers', '42', "{$kind}:lookup");

        $this->assertSame($seconds, $ttl);
        $this->assertIsString($stored);
        $this->assertNull(json_decode($stored, true), 'The stored value must not be readable without the app key.');

        $payload = json_decode(Crypt::decryptString($stored), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('key', $payload);
    }
}
