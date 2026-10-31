<?php

declare(strict_types=1);

namespace ContextGate\Http;

use ContextGate\Server\GateServer;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\SanctumServiceProvider;

/**
 * Registers the HTTP route with the package middleware after laravel/mcp's own (contracts/http-and-tokens.md).
 * Origin is checked before the token ceiling is read (US5 order). Refusals of the auth steps are written by
 * GateAuthRefusal, registered here.
 */
final class HttpRoute
{
    public static function register(): void
    {
        // Sanctum's guard config is set by its provider, which a host app discovers but a bare package test app does not.
        app()->register(SanctumServiceProvider::class);

        GateAuthRefusal::register();

        Mcp::web(config('context-gate.http.path'), GateServer::class)->middleware([
            'auth:sanctum',
            GateOriginCheck::class,
            GateTokenAuth::class,
            CheckAbilities::class.':gate:read',
        ]);
    }
}
