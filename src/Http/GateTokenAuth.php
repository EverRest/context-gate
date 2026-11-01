<?php

declare(strict_types=1);

namespace ContextGate\Http;

use Closure;
use ContextGate\Server\ReasonCode;
use ContextGate\Token\Ability;
use ContextGate\Token\Ceiling;
use ContextGate\Token\GateAccessToken;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after the gate guard (auth:context-gate). Every ability must be in the package set, and exactly one gate:ceiling:* ability must name a
 * valid ceiling; the ceiling is stored on the request for the spec 002 pipeline and is never read from the client
 * (FR-007, FR-012, research R8, R9).
 *
 * @internal May change without notice.
 */
final class GateTokenAuth
{
    public const CEILING_ATTRIBUTE = 'context_gate.ceiling';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof GateAccessToken) {
            return $this->refuse($request, 'unknown', ReasonCode::TokenInvalid, 401);
        }

        $caller = 'token:'.(string) $token->getKey();
        $abilities = (array) $token->getAttribute('abilities');

        $known = array_values(array_filter(
            $abilities,
            static fn (mixed $ability): bool => is_string($ability) && in_array($ability, Ability::package(), true),
        ));

        if (count($known) !== count($abilities)) {
            return $this->refuse($request, $caller, ReasonCode::TokenAbility, 403);
        }

        $ceilings = array_values(array_filter(
            $known,
            static fn (string $ability): bool => str_starts_with($ability, Ability::CEILING_PREFIX),
        ));

        $ceiling = count($ceilings) === 1
            ? Ceiling::tryFrom(substr($ceilings[0], strlen(Ability::CEILING_PREFIX)))
            : null;

        if ($ceiling === null) {
            return $this->refuse($request, $caller, ReasonCode::TokenNoCeiling, 401);
        }

        $request->attributes->set(self::CEILING_ATTRIBUTE, $ceiling);

        return $next($request);
    }

    private function refuse(Request $request, string $caller, ReasonCode $reason, int $status): Response
    {
        RefusalLedger::receipt($request, $caller, $reason);

        return GateAuthRefusal::body($status, $reason);
    }
}
