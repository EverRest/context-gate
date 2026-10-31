<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Source\RoutesSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Source\SourceResult;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Route;

class RoutesExclusionTest extends TestCase
{
    private const DEFAULT_EXCLUDED = [
        'telescope/entries',
        'horizon/stats',
        '_ignition/health-check',
        '_debugbar/assets/stylesheets',
        'pulse/data',
        'sanctum/csrf-cookie',
        'telescope',
        'horizon',
        '_ignition',
        '_debugbar',
        'pulse',
        'sanctum',
    ];

    public function test_each_default_pattern_is_excluded(): void
    {
        Route::get('api/orders', fn (): string => 'ok');
        foreach (self::DEFAULT_EXCLUDED as $uri) {
            Route::get($uri, fn (): string => 'hidden');
        }

        $uris = $this->listedUris($this->read());

        $this->assertContains('api/orders', $uris);
        foreach (self::DEFAULT_EXCLUDED as $uri) {
            $this->assertNotContains($uri, $uris);
        }
    }

    public function test_each_default_package_name_is_excluded_when_it_is_the_whole_path(): void
    {
        Route::get('api/orders', fn (): string => 'ok');
        foreach (['telescope', 'horizon', 'pulse', '_ignition', 'livewire'] as $uri) {
            Route::get($uri, fn (): string => 'hidden');
        }

        $uris = $this->listedUris($this->read());

        $this->assertContains('api/orders', $uris);
        foreach (['telescope', 'horizon', 'pulse', '_ignition', 'livewire'] as $uri) {
            $this->assertNotContains($uri, $uris);
        }
    }

    public function test_a_config_pattern_adds_an_exclusion(): void
    {
        config()->set('context-gate.sources.routes.exclude', ['admin/*']);
        Route::get('admin/users', fn (): string => 'hidden');
        Route::get('api/orders', fn (): string => 'ok');

        $uris = $this->listedUris($this->read());

        $this->assertNotContains('admin/users', $uris);
        $this->assertContains('api/orders', $uris);
    }

    public function test_config_cannot_remove_a_default_exclusion(): void
    {
        config()->set('context-gate.sources.routes.exclude', []);
        Route::get('telescope/entries', fn (): string => 'hidden');

        $this->assertNotContains('telescope/entries', $this->listedUris($this->read()));
    }

    private function read(): SourceResult
    {
        $result = (new RoutesSource())->read(new SourceRequest([]));
        $this->assertTrue($result->ok);

        return $result;
    }

    /** @return list<string> */
    private function listedUris(SourceResult $result): array
    {
        /** @var list<array{uri: string}> $routes */
        $routes = $result->data['routes'];
        $uris = [];
        foreach ($routes as $route) {
            $uris[] = $route['uri'];
        }

        return $uris;
    }
}
