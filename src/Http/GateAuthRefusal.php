<?php

declare(strict_types=1);

namespace ContextGate\Http;

use ContextGate\Settings\ActiveSettings;
use ContextGate\Server\ReasonCode;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Writes the receipt and the 401 for the refusals of auth:sanctum (token.missing, token.invalid) and abilities
 * (token.no_read). Laravel's route pipeline renders these exceptions inside the pipe that threw them, so an outer
 * middleware cannot catch them; a renderable runs at that point instead. It answers only the HTTP route path and
 * returns null elsewhere, so the host application's own handling is unchanged. The caller is token:{id} when the
 * token resolved, otherwise unknown (constitution IV; the body names no check).
 *
 * @internal May change without notice.
 */
final class GateAuthRefusal
{
    public static function register(): void
    {
        $handler = app(ExceptionHandler::class);

        // The console binds a wrapper (Collision) that never renders an HTTP response; stdio needs no refusal receipts.
        if (! $handler instanceof Handler) {
            return;
        }

        $handler->renderable(static fn (AuthenticationException $e, Request $request): ?Response => self::refuse(
            $request,
            $request->bearerToken() === null ? ReasonCode::TokenMissing : ReasonCode::TokenInvalid,
        ));

        // Laravel turns an AuthorizationException into AccessDeniedHttpException before the renderables run; the
        // original is the previous exception.
        $handler->renderable(static fn (AccessDeniedHttpException $e, Request $request): ?Response => $e->getPrevious() instanceof MissingAbilityException
            ? self::refuse($request, ReasonCode::TokenNoRead, 403)
            : null);
    }

    private static function refuse(Request $request, ReasonCode $reason, int $status = 401): ?Response
    {
        $settings = app(ActiveSettings::class);

        if (! $settings->valid() || ! $request->is(ltrim($settings->httpPath(), '/'))) {
            return null;
        }

        $token = $request->user()?->currentAccessToken();
        $caller = $token instanceof PersonalAccessToken ? 'token:'.(string) $token->getKey() : 'unknown';

        RefusalLedger::receipt($request, $caller, $reason);

        return self::body($status, $reason);
    }

    /** The body names no check; the header names only the class of refusal. */
    public static function body(int $status, ReasonCode $reason): Response
    {
        return response()->json(
            ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'Unauthenticated.']],
            $status,
            ['WWW-Authenticate' => self::challenge($reason)],
        );
    }

    /** RFC 6750 3.1: no token carries no error code; a scope refusal is insufficient_scope; any other refused token is invalid_token. */
    private static function challenge(ReasonCode $reason): string
    {
        return match ($reason) {
            ReasonCode::TokenMissing => 'Bearer',
            ReasonCode::TokenNoRead, ReasonCode::TokenAbility => 'Bearer error="insufficient_scope"',
            default => 'Bearer error="invalid_token"',
        };
    }
}
