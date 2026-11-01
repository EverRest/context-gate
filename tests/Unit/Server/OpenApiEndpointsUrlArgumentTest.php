<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Server\ArgumentValidator;
use ContextGate\Server\Sources\OpenApiEndpointsTool;
use ContextGate\Source\Fetch\HttpReply;
use ContextGate\Tests\Fakes\FakeResolver;
use ContextGate\Tests\Fakes\FakeTransport;
use ContextGate\Tests\TestCase;

/** T030: openapi.endpoints accepts a url argument, and the configured allowlist reaches the URL source. */
class OpenApiEndpointsUrlArgumentTest extends TestCase
{
    private const URL = 'https://api.example.test/openapi.json';

    public function test_schema_accepts_a_url_argument(): void
    {
        $problem = (new ArgumentValidator())->validate(new OpenApiEndpointsTool(), ['url' => self::URL]);

        $this->assertNull($problem);
    }

    public function test_configured_allowlist_reaches_the_url_source(): void
    {
        config(['context-gate.sources.openapi.paths' => ['openapi/']]);
        config(['context-gate.sources.openapi.allowlist' => ['api.example.test']]);
        $fixture = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/openapi/valid-3.1.json');
        $resolver = new FakeResolver([['203.0.113.10']]);
        $transport = new FakeTransport(new HttpReply(200, 'application/json', $fixture));

        $rows = json_decode((string) (new OpenApiEndpointsTool($resolver, $transport))->handle(['url' => self::URL])->content(), true);

        $this->assertCount(2, $rows);
        $this->assertSame(1, $resolver->lookups);
    }
}
