<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Privacy;

use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Tests\TestCase;

class PseudonymizerTest extends TestCase
{
    public function test_same_record_and_scope_give_the_same_value(): void
    {
        $scope = 'session:'.uniqid('', true);
        $pseudonymizer = new Pseudonymizer();

        $first = $pseudonymizer->pseudonym('cus', 'customers', '42', $scope);

        $this->assertSame($first, $pseudonymizer->pseudonym('cus', 'customers', '42', $scope));
    }

    public function test_different_scope_gives_a_different_value(): void
    {
        $pseudonymizer = new Pseudonymizer();

        $first = $pseudonymizer->pseudonym('cus', 'customers', '42', 'session:'.uniqid('', true));
        $second = $pseudonymizer->pseudonym('cus', 'customers', '42', 'session:'.uniqid('', true));

        $this->assertNotSame($first, $second);
    }

    public function test_value_is_the_prefix_and_ten_hex_characters(): void
    {
        $value = (new Pseudonymizer())->pseudonym('cus', 'customers', '42', 'session:'.uniqid('', true));

        $this->assertMatchesRegularExpression('/^cus_[0-9a-f]{10}$/', $value);
    }
}
