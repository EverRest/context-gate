<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Token;

use ContextGate\Tests\TestCase;
use ContextGate\Token\GateAccessToken;
use DateTimeImmutable;
use DateTimeZone;

class GateAccessTokenExpiryTest extends TestCase
{
    private function expiresAt(mixed $raw): mixed
    {
        return (new GateAccessToken())->setRawAttributes(['expires_at' => $raw])->getAttribute('expires_at');
    }

    public function test_null_stays_null_and_strings_are_read_as_utc(): void
    {
        $this->assertNull($this->expiresAt(null));
        $this->assertSame(1_700_000_000, $this->expiresAt('2023-11-14 22:13:20')->getTimestamp());
    }

    public function test_datetime_objects_are_converted_to_utc(): void
    {
        $at = new DateTimeImmutable('2023-11-15 00:13:20', new DateTimeZone('Europe/Kyiv'));

        $this->assertSame(1_700_000_000, $this->expiresAt($at)->getTimestamp());
    }

    public function test_unexpected_raw_values_fail_closed_as_expired(): void
    {
        foreach ([123, 1.5, true, ['x']] as $raw) {
            $this->assertTrue($this->expiresAt($raw)->isPast());
        }
    }
}
