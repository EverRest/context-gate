<?php

declare(strict_types=1);

namespace ContextGate\Http;

use Closure;
use ContextGate\Server\ReasonCode;
use ContextGate\Token\GateAccessToken;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a browser Origin that is not an exact entry in config context-gate.http.origins.
 * A request without an Origin header is a non-browser client and passes. Two Origin headers, the literal `null`
 * origin and an empty value are always refused, even when the allowlist names them.
 *
 * @internal May change without notice.
 */
final class GateOriginCheck
{
    public function handle(Request $request, Closure $next): Response
    {
        $origins = $request->headers->all('Origin');

        if ($origins === []) {
            return $next($request);
        }

        $allowed = config('context-gate.http.origins', []);
        $origin = $origins[0];

        if (count($origins) === 1 && $origin !== '' && $origin !== 'null' && is_array($allowed) && in_array($origin, $allowed, true)) {
            return $next($request);
        }

        // Runs before auth:context-gate, so the user is not resolved yet: read the bearer token directly.
        // Only a well-formed bearer can resolve to a token, so a malformed one costs no query.
        $bearer = $request->bearerToken();
        $token = $bearer !== null && RefusalLedger::looksLikeToken($bearer) ? GateAccessToken::findToken($bearer) : null;
        $caller = $token instanceof GateAccessToken ? 'token:'.(string) $token->getKey() : 'unknown';

        RefusalLedger::receipt($request, $caller, ReasonCode::OriginDenied);

        return response('', 403);
    }
}
