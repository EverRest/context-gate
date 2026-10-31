<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Contract;

use ContextGate\Contract\RuleHash;
use PHPUnit\Framework\TestCase;

class RuleHashTest extends TestCase
{
    public function test_a_hash_is_six_hex_characters(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{6}$/', RuleHash::of('orders.status:plain'));
    }

    public function test_identical_input_gives_identical_output(): void
    {
        $this->assertSame(
            RuleHash::of('customers.email:pseudonym(USR)'),
            RuleHash::of('customers.email:pseudonym(USR)'),
        );
    }

    public function test_a_changed_field_form_gives_a_different_hash(): void
    {
        $this->assertNotSame(
            RuleHash::of('customers.email:pseudonym(USR)'),
            RuleHash::of('customers.email:plain'),
        );
    }
}
