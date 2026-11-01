<?php

declare(strict_types=1);

namespace ContextGate\Readiness\Checks;

use ContextGate\Readiness\Check;
use ContextGate\Readiness\CheckResult;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Settings\ConfigOverlay;
use ContextGate\Settings\ConfigVersionStore;
use ContextGate\Settings\EffectiveSettings;
use ContextGate\Settings\SettingsValidator;

/**
 * spec 007 US1, FR-001, FR-005, FR-011: the whole settings document, every violation named in one result.
 *
 * @internal May change without notice.
 */
final class SettingsCheck implements Check
{
    public function name(): string
    {
        return self::class;
    }

    public function run(ReadinessContext $ctx): CheckResult
    {
        $violations = [
            ...SettingsValidator::violations($ctx->config, EffectiveSettings::environment()),
            ...ConfigOverlay::violations(ConfigVersionStore::fromApp()),
        ];

        if ($violations === []) {
            return CheckResult::pass('context-gate.settings', 'every setting is valid for this environment');
        }

        $keys = array_values(array_unique(array_column($violations, 'key')));
        $details = array_map(static fn (array $v): string => "{$v['key']} {$v['message']}", $violations);

        return CheckResult::fail(
            'context-gate.'.$keys[0],
            implode('; ', $details),
            'correct '.implode(', ', $keys).' in config/context-gate.php',
        );
    }

    public function fixHint(): string
    {
        return 'correct the keys named in the result in config/context-gate.php';
    }
}
