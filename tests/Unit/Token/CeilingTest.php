<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Token;

use ContextGate\Token\Ceiling;
use PHPUnit\Framework\TestCase;
use ValueError;

class CeilingTest extends TestCase
{
    public function test_token_ceilings_are_public_internal_and_personal_in_order(): void
    {
        $this->assertSame(
            ['public', 'internal', 'personal'],
            array_map(static fn (Ceiling $case): string => $case->value, Ceiling::cases()),
        );
    }

    public function test_secret_is_not_a_token_ceiling(): void
    {
        $this->expectException(ValueError::class);

        Ceiling::from('secret');
    }

    public function test_the_three_token_ceilings_are_valid(): void
    {
        $this->assertSame(Ceiling::Public, Ceiling::from('public'));
        $this->assertSame(Ceiling::Internal, Ceiling::from('internal'));
        $this->assertSame(Ceiling::Personal, Ceiling::from('personal'));
    }
}
