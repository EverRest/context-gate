<?php

declare(strict_types=1);

namespace ContextGate\Readiness\Checks;

use ContextGate\Settings\ActiveSettings;
use ContextGate\Readiness\Check;
use ContextGate\Readiness\CheckResult;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Settings\ConfigOverlay;
use ContextGate\Settings\ConfigVersionStore;
use ContextGate\Settings\SettingsValidator;

/**
 * The whole settings document, every violation named in one result.
 *
 * @internal May change without notice.
 */
final class SettingsCheck implements Check
{
    /** Overlay keys (ConfigOverlay::violations) are stored files, not keys in config/context-gate.php. */
    private const STORED_FIXES = [
        'versions/index.json' => 'repair or remove storage/app/context-gate/config/index.json, then run gate:config:import',
        'versions' => 'import or roll back to a valid version',
    ];

    public function name(): string
    {
        return self::class;
    }

    public function run(ReadinessContext $ctx): CheckResult
    {
        $violations = [
            // No cap rule here: CapsCheck reports the over-cap limits, so each one is one FAIL line.
            ...SettingsValidator::violations($ctx->config, app(ActiveSettings::class)->environment(), []),
            ...ConfigOverlay::violations(ConfigVersionStore::fromApp()),
        ];

        if ($violations === []) {
            return CheckResult::pass('context-gate.settings', 'every setting is valid for this environment');
        }

        $keys = array_values(array_unique(array_column($violations, 'key')));
        $details = array_map(static fn (array $v): string => "{$v['key']} {$v['message']}", $violations);
        $settingKeys = array_values(array_diff($keys, array_keys(self::STORED_FIXES)));
        $fixes = $settingKeys === [] ? [] : ['correct '.implode(', ', $settingKeys).' in config/context-gate.php'];

        foreach ($keys as $key) {
            if (isset(self::STORED_FIXES[$key])) {
                $fixes[] = self::STORED_FIXES[$key];
            }
        }

        return CheckResult::fail('context-gate.'.$keys[0], implode('; ', $details), implode('; ', $fixes));
    }

    public function fixHint(): string
    {
        return 'correct the keys named in the result in config/context-gate.php';
    }
}
