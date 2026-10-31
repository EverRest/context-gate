<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Contract;

use ContextGate\Contract\Field;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FieldScopeTest extends TestCase
{
    public function test_session_day_and_week_are_accepted(): void
    {
        $this->assertSame('session', Field::pseudonym('USR')->options['scope']);
        $this->assertSame('day', Field::pseudonym('USR', 'day')->options['scope']);
        $this->assertSame('week', Field::pseudonym('USR', 'week')->options['scope']);
    }

    #[DataProvider('rejectedScopes')]
    public function test_any_other_scope_is_rejected(string $scope): void
    {
        $this->expectException(InvalidArgumentException::class);

        Field::pseudonym('USR', $scope);
    }

    /** @return array<string, array{string}> */
    public static function rejectedScopes(): array
    {
        return [
            'month' => ['month'],
            'year' => ['year'],
            'forever' => ['forever'],
            'wrong case' => ['Day'],
            'empty' => [''],
        ];
    }
}
