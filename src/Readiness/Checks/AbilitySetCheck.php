<?php

declare(strict_types=1);

namespace ContextGate\Readiness\Checks;

use ContextGate\Readiness\Check;
use ContextGate\Readiness\CheckResult;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Token\Ability;
use ContextGate\Token\GateAccessToken;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** spec 005 FR-004, spec 006 FR-011: every existing token carries only package abilities. Values are never read out. */
final class AbilitySetCheck implements Check
{
    public function name(): string
    {
        return self::class;
    }

    public function run(ReadinessContext $ctx): CheckResult
    {
        try {
            if (! Schema::hasTable('gate_access_tokens')) {
                return CheckResult::warn('gate_access_tokens', 'table is missing; abilities were not checked', 'run php artisan migrate, then gate:check again');
            }

            foreach (GateAccessToken::query()->get(['id', 'abilities']) as $token) {
                foreach ((array) $token->getAttribute('abilities') as $ability) {
                    if (! is_string($ability) || ! Ability::isPackageAbility($ability)) {
                        return CheckResult::fail(
                            'gate_access_tokens',
                            "token {$token->getKey()} has ability {$this->label($ability)}, which is outside the package set",
                            $this->fixHint(),
                        );
                    }
                }
            }
        } catch (Throwable) {
            return CheckResult::warn('gate_access_tokens', 'database unavailable; abilities were not checked', 'check the database connection, then run gate:check again');
        }

        return CheckResult::pass('gate_access_tokens', 'every token has package abilities only');
    }

    private function label(mixed $ability): string
    {
        return is_string($ability) ? "'{$ability}'" : 'that is not a string';
    }

    public function fixHint(): string
    {
        return 'revoke the token with gate:token --revoke and issue a new one with package abilities only';
    }
}
