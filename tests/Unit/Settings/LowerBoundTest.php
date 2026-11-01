<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Config\CapsValidator;
use ContextGate\Settings\SettingsValidator;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** spec 007 FR-010, review F8: every numeric limit has a lower bound of 1; zero and negative values are refused by name. */
class LowerBoundTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function nonPositive(): iterable
    {
        foreach (array_keys(CapsValidator::caps()) as $key) {
            yield "{$key} is zero" => [$key, 0];
            yield "{$key} is negative" => [$key, -1];
        }
    }

    #[DataProvider('nonPositive')]
    public function test_a_zero_or_negative_limit_is_refused_naming_its_key(string $key, int $value): void
    {
        $document = [];
        Arr::set($document, $key, $value);

        $this->assertContains($key, array_column(SettingsValidator::violations($document, 'testing'), 'key'));
    }
}
