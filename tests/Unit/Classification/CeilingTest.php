<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Classification;

use ContextGate\Classification\Ceiling;
use PHPUnit\Framework\TestCase;

class CeilingTest extends TestCase
{
    public function test_has_the_four_classes_in_order(): void
    {
        $values = array_map(static fn (Ceiling $case): string => $case->value, Ceiling::cases());

        $this->assertSame(['public', 'internal', 'personal', 'secret'], $values);
    }

    public function test_a_class_is_allowed_when_it_is_at_or_below_the_ceiling(): void
    {
        $this->assertTrue(Ceiling::allows(Ceiling::Public, Ceiling::Internal));
        $this->assertTrue(Ceiling::allows(Ceiling::Internal, Ceiling::Internal));
        $this->assertTrue(Ceiling::allows(Ceiling::Internal, Ceiling::Secret));
        $this->assertTrue(Ceiling::allows(Ceiling::Personal, Ceiling::Personal));
    }

    public function test_a_class_above_the_ceiling_is_not_allowed(): void
    {
        $this->assertFalse(Ceiling::allows(Ceiling::Internal, Ceiling::Public));
        $this->assertFalse(Ceiling::allows(Ceiling::Personal, Ceiling::Internal));
        $this->assertFalse(Ceiling::allows(Ceiling::Secret, Ceiling::Personal));
    }
}
