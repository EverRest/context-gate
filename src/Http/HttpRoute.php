<?php

declare(strict_types=1);

namespace ContextGate\Http;

use ContextGate\Server\GateServer;
use ContextGate\Token\GateAccessToken;
use ContextGate\Token\GateTokenHolder;
use ContextGate\Token\TokenIssuer;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\SanctumServiceProvider;

/**
 * Registers the HTTP route with the package middleware after laravel/mcp's own (contracts/http-and-tokens.md).
 * Origin is checked before the token ceiling is read (US5 order). Refusals of the auth steps are written by
 * GateAuthRefusal, registered here.
 *
 * The route authenticates with the gate's own guard, not auth:sanctum: a gate token is refused by the host
 * application's routes and a host token is refused by this one.
 */
final class HttpRoute
{
    public const GUARD = 'context-gate';

    public static function register(): void
    {
        // The host's auth:sanctum routes use Sanctum's guard; the test app and bare installs need it registered.
        app()->register(SanctumServiceProvider::class);

        GateAuthRefusal::register();
        self::registerGuard();

        Mcp::web(config('context-gate.http.path'), GateServer::class)->middleware([
            GateOriginCheck::class,
            'auth:'.self::GUARD,
            GateTokenAuth::class,
            CheckAbilities::class.':gate:read',
        ]);
    }

    /** The bearer token must be a gate token that has not expired; its holder is the request user. */
    private static function registerGuard(): void
    {
        config(['auth.guards.'.self::GUARD => ['driver' => self::GUARD, 'provider' => null]]);

        Auth::viaRequest(self::GUARD, static function (Request $request): ?GateTokenHolder {
            $bearer = $request->bearerToken();
            $token = $bearer === null ? null : GateAccessToken::findToken($bearer);
            $expiresAt = $token?->getAttribute('expires_at');
            $createdAt = $token?->getAttribute('created_at');
            $holder = $token?->getAttribute('tokenable');

            // A token without an expiry, or with one more than the issuance maximum after creation, is refused (FR-006).
            if (! $token instanceof GateAccessToken
                || ! $expiresAt instanceof DateTimeInterface
                || $expiresAt <= Carbon::now()
                || ! $createdAt instanceof DateTimeInterface
                || $expiresAt > Carbon::instance($createdAt)->addDays(TokenIssuer::MAX_DAYS)
                || ! $holder instanceof GateTokenHolder) {
                return null;
            }

            return $holder->withAccessToken($token);
        });
    }
}
