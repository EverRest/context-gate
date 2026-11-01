<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Source\Fetch\HttpReply;
use ContextGate\Server\Sources\OpenApiEndpointsTool;
use ContextGate\Tests\Fakes\FakeResolver;
use ContextGate\Tests\Fakes\FakeTransport;
use ContextGate\Tests\TestCase;

/** spec 007 FR-017 (T031, research R2): the OpenAPI URL fetch uses the timeout and size of the active profile. */
class OpenApiLimitsTest extends TestCase
{
    private const URL = 'https://api.example.test/openapi.json';

    /** @return array{timeout: float, maxBytes: int} the limits the transport received */
    private function fetchUnder(?string $profile): array
    {
        config(['context-gate.profile' => $profile]);
        config(['context-gate.sources.openapi.paths' => ['openapi/']]);
        config(['context-gate.sources.openapi.allowlist' => ['api.example.test']]);
        $fixture = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/openapi/valid-3.1.json');
        $transport = new FakeTransport(new HttpReply(200, 'application/json', $fixture));

        (new OpenApiEndpointsTool(new FakeResolver([['203.0.113.10']]), $transport))->handle(['url' => self::URL]);

        return ['timeout' => $transport->calls[0]['timeout'], 'maxBytes' => $transport->calls[0]['maxBytes']];
    }

    public function test_a_default_install_fetches_with_the_strict_timeout_and_size(): void
    {
        $this->assertSame(['timeout' => 2.5, 'maxBytes' => 1048576], $this->fetchUnder(null));
    }

    public function test_balanced_fetches_with_the_code_caps(): void
    {
        $this->assertSame(['timeout' => 5.0, 'maxBytes' => 2097152], $this->fetchUnder('balanced'));
    }
}
