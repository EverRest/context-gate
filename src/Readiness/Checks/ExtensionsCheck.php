<?php

declare(strict_types=1);

namespace ContextGate\Readiness\Checks;

use Closure;
use ContextGate\Readiness\Check;
use ContextGate\Readiness\CheckResult;
use ContextGate\Readiness\ReadinessContext;

/**
 * spec 006 FR-009: the PHP extensions the package calls directly are loaded (curl: HttpTransport; mbstring, intl).
 *
 * @internal May change without notice.
 */
class ExtensionsCheck implements Check
{
    private const REQUIRED = ['curl', 'mbstring', 'intl'];

    /** @param (Closure(string): bool)|null $loaded a test seam; extension_loaded when null */
    public function __construct(private readonly ?Closure $loaded = null)
    {
    }

    public function name(): string
    {
        return self::class;
    }

    public function run(ReadinessContext $ctx): CheckResult
    {
        $loaded = $this->loaded ?? extension_loaded(...);

        foreach (self::REQUIRED as $extension) {
            if (! $loaded($extension)) {
                return CheckResult::fail("ext-{$extension}", "the PHP {$extension} extension is not loaded", $this->fixHint());
            }
        }

        return CheckResult::pass('ext-'.implode(', ext-', self::REQUIRED), 'all are loaded');
    }

    public function fixHint(): string
    {
        return 'enable the PHP curl, mbstring and intl extensions';
    }
}
