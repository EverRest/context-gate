<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness\Checks;

use ContextGate\Classification\Catalog;
use ContextGate\Readiness\CheckStatus;
use ContextGate\Readiness\Checks\ExtensionsCheck;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Tests\TestCase;

/** T024, spec 006 FR-009: ext-intl and symfony/yaml must be present; the lookups are simulated here. */
class ExtensionsCheckTest extends TestCase
{
    private function context(): ReadinessContext
    {
        return new ReadinessContext([], new Catalog([]), []);
    }

    public function test_a_missing_ext_intl_fails_naming_it(): void
    {
        $check = new class () extends ExtensionsCheck {
            protected function extensionLoaded(string $extension): bool
            {
                return $extension !== 'intl';
            }
        };

        $result = $check->run($this->context());

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame('ext-intl', $result->subject);
    }

    public function test_a_missing_symfony_yaml_fails_naming_it(): void
    {
        $check = new class () extends ExtensionsCheck {
            protected function classExists(string $class): bool
            {
                return false;
            }
        };

        $result = $check->run($this->context());

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame('symfony/yaml', $result->subject);
    }

    public function test_both_present_pass(): void
    {
        $this->assertSame(CheckStatus::Pass, (new ExtensionsCheck())->run($this->context())->status);
    }
}
