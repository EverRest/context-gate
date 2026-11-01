<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Config\CapsValidator;
use ContextGate\Pipeline\Caps;
use ContextGate\Settings\SettingsValidator;
use PHPUnit\Framework\TestCase;

/** spec 007 T012 (FR-001, FR-005, FR-011): one validator reports every violation with its key. */
class SettingsValidatorTest extends TestCase
{
    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    private function keysOf(array $document, string $env = 'testing'): array
    {
        return array_column(SettingsValidator::violations($document, $env), 'key');
    }

    public function test_an_empty_document_is_valid(): void
    {
        $this->assertSame([], SettingsValidator::violations([], 'production'));
    }

    public function test_every_violation_is_reported_in_one_call(): void
    {
        $document = [
            'firewall' => ['limits' => ['per_call_bytes' => Caps::PER_CALL + 1]],
            'profile' => 'balanced',
            'typo' => true,
        ];

        $this->assertSame(['typo', 'firewall.limits.per_call_bytes', 'profile'], $this->keysOf($document, 'production'));
    }

    public function test_a_limit_at_its_cap_passes_and_one_above_fails(): void
    {
        $this->assertSame([], $this->keysOf(['firewall' => ['limits' => ['per_call_bytes' => Caps::PER_CALL]]]));
        $this->assertSame(
            ['firewall.limits.per_call_bytes'],
            $this->keysOf(['firewall' => ['limits' => ['per_call_bytes' => Caps::PER_CALL + 1]]]),
        );
    }

    public function test_balanced_in_production_fails_and_names_the_profile(): void
    {
        $this->assertSame(['profile'], $this->keysOf(['profile' => 'balanced'], 'production'));
    }

    public function test_balanced_outside_production_passes(): void
    {
        $this->assertSame([], $this->keysOf(['profile' => 'balanced'], 'development'));
    }

    public function test_a_caller_tool_missing_from_tools_fails_and_names_the_caller_key(): void
    {
        $document = [
            'tools' => ['probe' => ['enabled' => true]],
            'callers' => ['a' => ['tools' => ['missing']]],
        ];

        $this->assertSame(['callers.a.tools'], $this->keysOf($document));
    }

    public function test_the_cap_map_covers_the_two_connector_keys(): void
    {
        $caps = CapsValidator::caps();

        $this->assertSame(Caps::OPENAPI_TIMEOUT, $caps['sources.openapi.timeout_seconds']);
        $this->assertSame(Caps::OPENAPI_BYTES, $caps['sources.openapi.max_bytes']);
    }

    public function test_a_connector_limit_above_its_cap_fails_naming_its_key(): void
    {
        $this->assertSame(
            ['sources.openapi.timeout_seconds'],
            $this->keysOf(['sources' => ['openapi' => ['timeout_seconds' => Caps::OPENAPI_TIMEOUT + 1]]]),
        );
    }
}
