<?php

declare(strict_types=1);

namespace ContextGate\Readiness\Checks;

use ContextGate\Readiness\Check;
use ContextGate\Readiness\CheckResult;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Token\Ability;
use ContextGate\Token\GateAccessToken;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Every active token (issued, not revoked, not expired) has a role that is defined in roles. A token whose role is
 * missing or not defined sees no tools, so this warns and never fails. The warning names the token id, the holder and
 * the role, never a token value. Read-only.
 *
 * @internal May change without notice.
 */
final class TokenRolesCheck implements Check
{
    public function name(): string
    {
        return self::class;
    }

    public function run(ReadinessContext $ctx): CheckResult
    {
        $roles = is_array($ctx->config['roles'] ?? null) ? $ctx->config['roles'] : [];

        try {
            // Revoked tokens have no row (gate:token --revoke deletes it); expired ones are filtered by expires_at.
            $active = GateAccessToken::query();
            $active->where('expires_at', '>', Carbon::now('UTC'))->orderBy('id');
            $tokens = $active->get();
        } catch (Throwable) {
            return CheckResult::warn('roles', 'database unavailable; the token roles were not checked', 'check the database connection, then run gate:check again');
        }

        $missing = [];

        foreach ($tokens as $token) {
            $role = self::roleOf($token);

            if ($role === null || ! array_key_exists($role, $roles)) {
                $missing[] = 'token '.$token->getKey().' (holder '.(string) $token->getAttribute('name').') has role '.($role ?? 'none').', which is not defined; it sees no tools';
            }
        }

        if ($missing === []) {
            return CheckResult::pass('roles', 'every active token has a defined role');
        }

        return CheckResult::warn(
            'roles',
            implode('; ', $missing),
            'define each role in roles in config/context-gate.php, or revoke the token with gate:token --revoke=<id> and issue a new one with --role',
        );
    }

    public function fixHint(): string
    {
        return 'define each token role in roles in config/context-gate.php';
    }

    private static function roleOf(GateAccessToken $token): ?string
    {
        $abilities = $token->getAttribute('abilities');

        foreach (is_array($abilities) ? $abilities : [] as $ability) {
            if (is_string($ability) && str_starts_with($ability, Ability::ROLE_PREFIX)) {
                return substr($ability, strlen(Ability::ROLE_PREFIX));
            }
        }

        return null;
    }
}
