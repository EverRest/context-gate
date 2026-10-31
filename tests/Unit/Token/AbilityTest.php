<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Token;

use ContextGate\Token\Ability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AbilityTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function allowed(): iterable
    {
        yield 'read' => ['gate:read'];
        yield 'ceiling public' => ['gate:ceiling:public'];
        yield 'ceiling internal' => ['gate:ceiling:internal'];
        yield 'ceiling personal' => ['gate:ceiling:personal'];
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'ceiling secret' => ['gate:ceiling:secret'];
        yield 'write' => ['gate:write'];
        yield 'wildcard' => ['*'];
    }

    #[DataProvider('allowed')]
    public function test_package_abilities_are_accepted(string $ability): void
    {
        $this->assertTrue(Ability::isPackageAbility($ability));
    }

    #[DataProvider('refused')]
    public function test_other_abilities_are_refused(string $ability): void
    {
        $this->assertFalse(Ability::isPackageAbility($ability));
    }
}
