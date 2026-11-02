<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Settings\SettingsValidator;
use PHPUnit\Framework\TestCase;

/** spec 010 FR-001..FR-005, FR-009, FR-012, FR-015; research R1: the roles section is validated with the rest of the document. */
class RoleSettingsTest extends TestCase
{
    /**
     * @param array<string, mixed> $role
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function document(array $role = [], array $extra = []): array
    {
        return array_merge([
            'tools' => ['probe' => ['enabled' => true]],
            'roles' => ['x' => array_merge(['tools' => ['probe'], 'ceiling' => 'internal'], $role)],
        ], $extra);
    }

    /**
     * @param array<string, mixed> $document
     * @return list<array{key: string, message: string}>
     */
    private function violationsOf(array $document, string $env = 'testing'): array
    {
        return SettingsValidator::violations($document, $env);
    }

    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    private function keysOf(array $document, string $env = 'testing'): array
    {
        return array_column($this->violationsOf($document, $env), 'key');
    }

    public function test_a_valid_role_passes(): void
    {
        $this->assertSame([], $this->violationsOf($this->document()));
    }

    public function test_an_empty_tool_list_passes(): void
    {
        $this->assertSame([], $this->keysOf($this->document(['tools' => []])));
    }

    public function test_a_role_name_that_is_not_a_plain_identifier_fails(): void
    {
        $document = ['tools' => ['probe' => ['enabled' => true]], 'roles' => ['a b' => ['tools' => [], 'ceiling' => 'internal']]];

        $this->assertSame(['roles.a b'], $this->keysOf($document));
    }

    public function test_a_role_name_over_64_characters_fails(): void
    {
        $document = ['roles' => [str_repeat('r', 65) => ['tools' => [], 'ceiling' => 'internal']]];

        $this->assertSame(['roles.'.str_repeat('r', 65)], $this->keysOf($document));
    }

    public function test_a_role_listing_a_tool_missing_from_tools_fails_naming_its_tools_key(): void
    {
        $this->assertSame(['roles.x.tools'], $this->keysOf($this->document(['tools' => ['missing']])));
    }

    public function test_a_role_ceiling_of_secret_fails_naming_its_ceiling_key(): void
    {
        $this->assertSame(['roles.x.ceiling'], $this->keysOf($this->document(['ceiling' => 'secret'])));
    }

    public function test_a_role_without_a_ceiling_fails_naming_its_ceiling_key(): void
    {
        $document = $this->document();
        unset($document['roles']['x']['ceiling']);

        $this->assertSame(['roles.x.ceiling'], $this->keysOf($document));
    }

    public function test_a_role_limit_above_the_strict_profile_value_fails_under_production(): void
    {
        $this->assertSame(['roles.x.limits.per_call_bytes'], $this->keysOf($this->document(['limits' => ['per_call_bytes' => 16385]]), 'production'));
    }

    public function test_a_role_limit_equal_to_the_profile_value_passes(): void
    {
        $this->assertSame([], $this->keysOf($this->document(['limits' => ['per_call_bytes' => 16384]]), 'production'));
    }

    public function test_a_non_empty_role_scope_fails_naming_its_scope_key(): void
    {
        $this->assertSame(['roles.x.scope'], $this->keysOf($this->document(['scope' => ['tenant' => 'a']])));
    }

    public function test_an_empty_role_scope_passes(): void
    {
        $this->assertSame([], $this->keysOf($this->document(['scope' => []])));
    }

    public function test_an_unknown_key_under_a_role_fails_naming_it(): void
    {
        $this->assertSame(['roles.x.color'], $this->keysOf($this->document(['color' => 'red'])));
    }

    public function test_a_stdio_user_mapped_to_an_undefined_role_fails_naming_the_user(): void
    {
        $this->assertSame(['stdio_roles.dev'], $this->keysOf($this->document([], ['stdio_roles' => ['dev' => 'nobody']])));
    }

    public function test_a_stdio_user_mapped_to_a_defined_role_passes(): void
    {
        $this->assertSame([], $this->keysOf($this->document([], ['stdio_roles' => ['dev' => 'x']])));
    }

    public function test_a_callers_key_fails_and_points_to_roles(): void
    {
        $violations = $this->violationsOf(['callers' => ['a' => ['tools' => []]]]);

        $this->assertSame('callers', $violations[0]['key']);
        $this->assertStringContainsString('roles', $violations[0]['message']);
    }
}
