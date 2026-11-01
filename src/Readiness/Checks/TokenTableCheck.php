<?php

declare(strict_types=1);

namespace ContextGate\Readiness\Checks;

use ContextGate\Readiness\Check;
use ContextGate\Readiness\CheckResult;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Token\GateAccessToken;
use Throwable;

/**
 * spec 005 FR-011: the three token tables exist. An empty token table is valid.
 *
 * @internal May change without notice.
 */
final class TokenTableCheck implements Check
{
    private const TABLES = ['gate_access_tokens', 'gate_token_holders', 'gate_token_issuers'];

    public function name(): string
    {
        return self::class;
    }

    public function run(ReadinessContext $ctx): CheckResult
    {
        try {
            // The connection TokenIssuer writes to: the token model's, not the default one.
            $connection = (new GateAccessToken())->getConnection();

            foreach (self::TABLES as $table) {
                if (! $connection->getSchemaBuilder()->hasTable($table)) {
                    return CheckResult::fail($table, 'table is missing; the package migrations have not run', $this->fixHint());
                }
            }

            $tokens = $connection->table('gate_access_tokens')->count();
        } catch (Throwable) {
            return CheckResult::warn('gate_access_tokens', 'database unavailable; the token tables were not checked', 'check the database connection, then run gate:check again');
        }

        return CheckResult::pass(
            'gate_access_tokens',
            $tokens === 0 ? 'table exists; no tokens issued yet' : "table exists; {$tokens} token rows",
        );
    }

    public function fixHint(): string
    {
        return 'run php artisan migrate so that the gate token tables exist';
    }
}
