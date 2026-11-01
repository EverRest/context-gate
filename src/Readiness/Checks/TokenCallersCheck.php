<?php

declare(strict_types=1);

namespace ContextGate\Readiness\Checks;

use ContextGate\Readiness\Check;
use ContextGate\Readiness\CheckResult;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Token\GateAccessToken;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Every active token (issued, not revoked, not expired) has a callers entry under token:<id>. A token without one sees
 * no tools, so this warns and never fails. The warning names the key and the holder, never a token value. Read-only.
 *
 * @internal May change without notice.
 */
final class TokenCallersCheck implements Check
{
    public function name(): string
    {
        return self::class;
    }

    public function run(ReadinessContext $ctx): CheckResult
    {
        $callers = $ctx->config['callers'] ?? [];
        $callers = is_array($callers) ? $callers : [];

        try {
            // Revoked tokens have no row (gate:token --revoke deletes it); expired ones are filtered by expires_at.
            $active = GateAccessToken::query();
            $active->where('expires_at', '>', Carbon::now('UTC'))->orderBy('id');
            $tokens = $active->get();
        } catch (Throwable) {
            return CheckResult::warn('callers', 'database unavailable; the token callers were not checked', 'check the database connection, then run gate:check again');
        }

        $missing = [];

        foreach ($tokens as $token) {
            $key = 'token:'.$token->getKey();

            if (! array_key_exists($key, $callers)) {
                $missing[] = $key.' (holder '.(string) $token->getAttribute('name').')';
            }
        }

        if ($missing === []) {
            return CheckResult::pass('callers', 'every active token has a callers entry');
        }

        return CheckResult::warn(
            'callers',
            'no callers entry for '.implode(', ', $missing).'; they see no tools',
            'add each key to callers in config/context-gate.php with the tools it may use, or revoke the token with gate:token --revoke=<id>',
        );
    }

    public function fixHint(): string
    {
        return 'add each token:<id> key to callers in config/context-gate.php';
    }
}
