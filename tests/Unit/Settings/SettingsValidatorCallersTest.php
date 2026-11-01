<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Settings\SettingsValidator;
use PHPUnit\Framework\TestCase;

/** spec 007 T028 (FR-016, data-model callers): the callers section is checked against tools and its own keys. */
class SettingsValidatorCallersTest extends TestCase
{
    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    private function keysOf(array $document): array
    {
        return array_column(SettingsValidator::violations($document, 'testing'), 'key');
    }

    public function test_a_caller_listing_a_tool_missing_from_tools_fails_naming_its_tools_key(): void
    {
        $this->assertSame(['callers.a.tools'], $this->keysOf([
            'tools' => ['probe' => ['enabled' => true]],
            'callers' => ['a' => ['tools' => ['missing']]],
        ]));
    }

    public function test_an_unknown_key_under_a_caller_fails_naming_it(): void
    {
        $this->assertSame(['callers.a.color'], $this->keysOf([
            'callers' => ['a' => ['tools' => [], 'color' => 'red']],
        ]));
    }

    public function test_an_empty_tool_list_passes(): void
    {
        $this->assertSame([], $this->keysOf(['callers' => ['a' => ['tools' => []]]]));
    }
}
