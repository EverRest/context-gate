<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness\Checks;

use ContextGate\Classification\Catalog;
use ContextGate\Readiness\CheckStatus;
use ContextGate\Readiness\Checks\UrlAllowlistCheck;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Tests\TestCase;

/** T028, spec 004 FR-005: an allowlist entry is a bare, lowercase host name. */
class UrlAllowlistCheckTest extends TestCase
{
    private function run_check(string $host): \ContextGate\Readiness\CheckResult
    {
        $ctx = new ReadinessContext(['sources' => ['openapi' => ['allowlist' => [$host]]]], new Catalog([]), []);

        return (new UrlAllowlistCheck())->run($ctx);
    }

    /** @return array<string, array{string}> */
    public static function badEntries(): array
    {
        return [
            'scheme' => ['https://docs.example.com'],
            'path' => ['docs.example.com/openapi.json'],
            'upper case' => ['Docs.example.com'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badEntries')]
    public function test_an_entry_with_a_scheme_or_a_path_fails_naming_it(string $host): void
    {
        $result = $this->run_check($host);

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame('context-gate.sources.openapi.allowlist[0]', $result->subject);
    }

    public function test_the_shipped_default_is_an_empty_allowlist_and_the_check_passes_with_it(): void
    {
        $this->assertSame([], config('context-gate.sources.openapi.allowlist'));

        $ctx = new ReadinessContext(['sources' => config('context-gate.sources')], new Catalog([]), []);

        $this->assertSame(CheckStatus::Pass, (new UrlAllowlistCheck())->run($ctx)->status);
    }

    public function test_a_bare_lowercase_host_passes(): void
    {
        $this->assertSame(CheckStatus::Pass, $this->run_check('docs.example.com')->status);
    }
}
