<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Source\RoutesSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Route;

class RoutesSecretSegmentTest extends TestCase
{
    public function test_a_32_character_random_segment_becomes_secret(): void
    {
        $key = bin2hex(random_bytes(16));
        Route::get('hooks/' . $key . '/ping', fn (): string => 'ok');

        $this->assertContains('hooks/<SECRET>/ping', $this->listedUris());
        $this->assertNotContains('hooks/' . $key . '/ping', $this->listedUris());
    }

    public function test_a_19_character_segment_is_kept(): void
    {
        Route::get('hooks/abcdefghijklmnopqrs/ping', fn (): string => 'ok');

        $this->assertContains('hooks/abcdefghijklmnopqrs/ping', $this->listedUris());
    }

    /** @return list<string> */
    private function listedUris(): array
    {
        $result = (new RoutesSource())->read(new SourceRequest([]));
        $this->assertTrue($result->ok);

        /** @var list<array{uri: string}> $routes */
        $routes = $result->data['routes'];
        $uris = [];
        foreach ($routes as $route) {
            $uris[] = $route['uri'];
        }

        return $uris;
    }
}
