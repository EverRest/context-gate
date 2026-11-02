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
use ContextGate\Settings\ActiveSettings;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\SanctumServiceProvider;

/**
 * Registers the HTTP route with the package middleware after laravel/mcp's own (contracts/http-and-tokens.md).
 * Origin is checked before the token ceiling is read. Refusals of the auth steps are written by
 * GateAuthRefusal, registered here.
 *
 * The route authenticates with the gate's own guard, not auth:sanctum: a gate token is refused by the host
 * application's routes and a host token is refused by this one.
 *
 * @internal May change without notice.
 */
final class HttpRoute
{
    public const GUARD = 'context-gate';

    /** Runs on every boot: the guard and the refusals are needed at request time even when the route comes from the route cache. */
    public static function registerAuth(): void
    {
        // Package discovery loads Sanctum's provider; register it only on bare and test installs without discovery.
        if (! app()->providerIsLoaded(SanctumServiceProvider::class)) {
            app()->register(SanctumServiceProvider::class);
        }

        GateAuthRefusal::register();
        self::registerGuard();
    }

    /** Skipped when routes are cached. */
    public static function register(): void
    {
        // laravel/mcp's AddWwwAuthenticateHeader rewrites every 401 to realm="mcp", error="invalid_token"; GateAuthRefusal
        // sets the RFC 6750 header per case instead, so the route drops that middleware (it also skips the global copy).
        // Built here, not resolved from the container: boot must not freeze the settings the process will use.
        $settings = ActiveSettings::fromApp(app());

        // The route exists whatever the settings say: a call under invalid settings reaches the server and is refused there
        // as config.invalid, with its receipt.
        Mcp::web(self::routePath($settings), GateServer::class)->middleware([
            GateRefusalThrottle::class,
            GateOriginCheck::class,
            'auth:'.self::GUARD,
            GateTokenAuth::class,
            CheckAbilities::class.':gate:read',
        ])->withoutMiddleware(AddWwwAuthenticateHeader::class);
    }

    /**
     * The path the route serves under any settings: the configured one when valid; else the path as written when it is a
     * non-empty string, or the shipped default. Boot never throws on a value.
     */
    private static function routePath(ActiveSettings $settings): string
    {
        if ($settings->valid()) {
            return $settings->httpPath();
        }

        $path = $settings->document()['http']['path'] ?? null;

        return is_string($path) && $path !== '' ? $path : 'mcp/context-gate';
    }

    /** The bearer token must be a gate token that has not expired; its holder is the request user. */
    private static function registerGuard(): void
    {
        // A guard the host defined under this name is kept.
        if (config('auth.guards.'.self::GUARD) === null) {
            config(['auth.guards.'.self::GUARD => ['driver' => self::GUARD, 'provider' => null]]);
        }

        Auth::viaRequest(self::GUARD, static function (Request $request): ?GateTokenHolder {
            $bearer = $request->bearerToken();
            $token = $bearer === null ? null : GateAccessToken::findToken($bearer);
            $expiresAt = $token?->getAttribute('expires_at');
            $createdAt = $token?->getAttribute('created_at');
            $holder = $token?->getAttribute('tokenable');

            // A token without an expiry, or with one more than the issuance maximum after creation, is refused.
            if (! $token instanceof GateAccessToken
                || ! $expiresAt instanceof DateTimeInterface
                || $expiresAt <= Carbon::now('UTC')
                || ! $createdAt instanceof DateTimeInterface
                || $expiresAt > Carbon::instance($createdAt)->addDays(TokenIssuer::MAX_DAYS)
                || ! $holder instanceof GateTokenHolder) {
                return null;
            }

            return $holder->withAccessToken($token);
        });
    }
}
