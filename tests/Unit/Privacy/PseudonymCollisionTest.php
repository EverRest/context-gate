<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Privacy;

use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Tests\TestCase;

class PseudonymCollisionTest extends TestCase
{
    public function test_a_colliding_record_gets_one_more_hex_character_and_both_stay_unique(): void
    {
        // Every record gets the same digest, so the second record must collide with the first.
        $pseudonymizer = new class () extends Pseudonymizer {
            protected function digest(string $key, string $table, string $id): string
            {
                return str_repeat('a', 64);
            }
        };
        $scope = 'session:'.uniqid('', true);

        $first = $pseudonymizer->pseudonym('cus', 'customers', '1', $scope);
        $second = $pseudonymizer->pseudonym('cus', 'customers', '2', $scope);

        $this->assertSame('cus_aaaaaaaaaa', $first);
        $this->assertSame('cus_aaaaaaaaaaa', $second);
        $this->assertSame($first, $pseudonymizer->pseudonym('cus', 'customers', '1', $scope));
    }
}
