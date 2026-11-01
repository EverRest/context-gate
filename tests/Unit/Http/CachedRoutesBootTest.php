<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Http;

use ContextGate\Http\HttpRoute;
use ContextGate\Tests\TestCase;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/** With a route cache the route comes from the cache, but the guard and the refusal renderables are still needed per request. */
class CachedRoutesBootTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->instance('routes.cached', true);
    }

    public function test_the_guard_is_defined_when_routes_are_cached(): void
    {
        $this->assertTrue(app()->routesAreCached());
        $this->assertNotNull(config('auth.guards.'.HttpRoute::GUARD));
        $this->assertInstanceOf(Guard::class, Auth::guard(HttpRoute::GUARD));
    }

    public function test_refusal_renderables_are_registered_when_routes_are_cached(): void
    {
        $request = Request::create('/'.config('context-gate.http.path'), 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
        $response = app(ExceptionHandler::class)->render($request, new AuthenticationException());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('jsonrpc', (string) $response->getContent());
    }

    public function test_the_mcp_route_itself_is_not_registered_when_routes_are_cached(): void
    {
        $uris = array_map(static fn ($route): string => $route->uri(), Route::getRoutes()->getRoutes());

        $this->assertNotContains((string) config('context-gate.http.path'), $uris);
    }
}
