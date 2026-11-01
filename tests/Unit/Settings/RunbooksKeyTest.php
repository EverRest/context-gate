<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Settings\SettingsValidator;
use PHPUnit\Framework\TestCase;

/** spec 018 T003 (FR-002, constitution VI): `runbooks` maps names to class strings and carries no limit. */
class RunbooksKeyTest extends TestCase
{
    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    private function keysOf(array $document): array
    {
        return array_column(SettingsValidator::violations($document, 'testing'), 'key');
    }

    public function test_an_empty_runbooks_map_is_valid(): void
    {
        $this->assertSame([], $this->keysOf(['runbooks' => []]));
    }

    public function test_a_name_mapped_to_a_class_string_is_valid(): void
    {
        $this->assertSame([], $this->keysOf(['runbooks' => ['disk' => 'App\\Runbooks\\DiskRunbook']]));
    }

    public function test_a_value_that_is_not_a_map_of_class_strings_is_reported(): void
    {
        $this->assertSame(['runbooks'], $this->keysOf(['runbooks' => 'App\\Runbooks\\DiskRunbook']));
        $this->assertSame(['runbooks.disk'], $this->keysOf(['runbooks' => ['disk' => 42]]));
    }

    public function test_runbooks_carries_no_code_cap(): void
    {
        $violations = SettingsValidator::violations(['runbooks' => ['disk' => 'App\\Runbooks\\DiskRunbook']], 'testing');

        $this->assertSame([], array_values(array_filter($violations, fn (array $v): bool => str_starts_with($v['key'], 'runbooks'))));
    }
}
