<?php

declare(strict_types=1);

namespace ContextGate\Readiness\Checks;

use ContextGate\Readiness\Check;
use ContextGate\Readiness\CheckResult;
use ContextGate\Readiness\ReadinessContext;
use Symfony\Component\Yaml\Yaml;

/** spec 006 FR-009: ext-intl and symfony/yaml are present. */
class ExtensionsCheck implements Check
{
    public function name(): string
    {
        return self::class;
    }

    public function run(ReadinessContext $ctx): CheckResult
    {
        if (! $this->extensionLoaded('intl')) {
            return CheckResult::fail('ext-intl', 'the PHP intl extension is not loaded', $this->fixHint());
        }

        if (! $this->classExists(Yaml::class)) {
            return CheckResult::fail('symfony/yaml', 'symfony/yaml is not installed', $this->fixHint());
        }

        return CheckResult::pass('ext-intl, symfony/yaml', 'both are available');
    }

    /** Overridable so a test can simulate a missing extension. */
    protected function extensionLoaded(string $extension): bool
    {
        return extension_loaded($extension);
    }

    /** Overridable so a test can simulate a missing package. */
    protected function classExists(string $class): bool
    {
        return class_exists($class);
    }

    public function fixHint(): string
    {
        return 'enable the PHP intl extension and run composer install so that symfony/yaml is present';
    }
}
