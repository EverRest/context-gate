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

    public function test_a_20_character_hex_segment_becomes_secret(): void
    {
        $key = bin2hex(random_bytes(10));
        Route::get('hooks/' . $key . '/ping', fn (): string => 'ok');

        $this->assertContains('hooks/<SECRET>/ping', $this->listedUris());
    }

    /** The mask starts at 20 characters (FR-003): a 15-character key-like segment is kept. */
    public function test_a_15_character_segment_is_kept(): void
    {
        Route::get('hooks/abcdefghijklmno/ping', fn (): string => 'ok');

        $this->assertContains('hooks/abcdefghijklmno/ping', $this->listedUris());
    }

    public function test_a_16_to_19_character_token_segment_is_secret(): void
    {
        Route::get('hooks/abcdefghijklmn01/ping', fn (): string => 'ok');

        $this->assertContains('hooks/<SECRET>/ping', $this->listedUris());
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
