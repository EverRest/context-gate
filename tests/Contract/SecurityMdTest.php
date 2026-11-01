<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use PHPUnit\Framework\TestCase;

/** FR-016: SECURITY.md has the threat model, scope, supported versions and a reporting channel with a response time. */
class SecurityMdTest extends TestCase
{
    private const SECTIONS = ['Threat model', 'Scope', 'Supported versions', 'Reporting'];

    public function test_security_md_has_the_required_sections(): void
    {
        $text = $this->text();

        foreach (self::SECTIONS as $heading) {
            $this->assertNotNull($this->section($text, $heading), "Missing section: {$heading}");
        }
    }

    public function test_reporting_section_states_a_response_time(): void
    {
        $reporting = $this->section($this->text(), 'Reporting');

        $this->assertNotNull($reporting);
        $this->assertMatchesRegularExpression('/\b\d+\s+(?:business\s+)?(?:hours?|days?|weeks?)\b/i', $reporting);
    }

    private function text(): string
    {
        $file = dirname(__DIR__, 2).'/SECURITY.md';
        $this->assertFileExists($file);

        return (string) file_get_contents($file);
    }

    private function section(string $text, string $heading): ?string
    {
        preg_match('/^## '.preg_quote($heading, '/').'\s*$(.*?)(?=^## |\z)/ms', $text, $matches);

        return $matches[1] ?? null;
    }
}
