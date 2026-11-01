<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness\Checks;

use ContextGate\Classification\Catalog;
use ContextGate\Readiness\CheckStatus;
use ContextGate\Readiness\Checks\ExtensionsCheck;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** spec 006 FR-009: the PHP extensions the package calls (curl, mbstring, intl) must be loaded; the lookup is simulated. */
class ExtensionsCheckTest extends TestCase
{
    private function context(): ReadinessContext
    {
        return new ReadinessContext([], new Catalog([]), []);
    }

    private function without(string $missing): ExtensionsCheck
    {
        return new ExtensionsCheck(static fn (string $extension): bool => $extension !== $missing);
    }

    #[DataProvider('required')]
    public function test_a_missing_extension_fails_naming_it_with_a_hint(string $extension): void
    {
        $result = $this->without($extension)->run($this->context());

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame("ext-{$extension}", $result->subject);
        $this->assertNotSame('', $result->fix);
    }

    /** @return array<string, array{string}> */
    public static function required(): array
    {
        return ['curl' => ['curl'], 'mbstring' => ['mbstring'], 'intl' => ['intl']];
    }

    public function test_all_present_pass(): void
    {
        $this->assertSame(CheckStatus::Pass, (new ExtensionsCheck(static fn (): bool => true))->run($this->context())->status);
    }
}
