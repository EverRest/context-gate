<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Source\Fetch\HttpReply;
use ContextGate\Source\OpenApiSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\Fakes\FakeResolver;
use ContextGate\Tests\Fakes\FakeTransport;
use ContextGate\Tests\TestCase;

/** User Story 2 acceptance 2 and the contract receipt fields: an allowlisted public host returns the fixture, pinned to its first lookup. */
class OpenApiUrlSuccessTest extends TestCase
{
    private const URL = 'https://api.example.test/openapi.json';

    public function test_allowlisted_public_host_returns_the_fixture_from_the_first_pinned_address(): void
    {
        $fixture = (string) file_get_contents(dirname(__DIR__) . '/Fixtures/openapi/valid-3.1.json');
        $resolver = new FakeResolver([['203.0.113.10'], ['127.0.0.1']]);
        $transport = new FakeTransport(new HttpReply(200, 'application/json', $fixture));
        $source = new OpenApiSource(dirname(__DIR__) . '/Fixtures', ['openapi/'], ['api.example.test'], $resolver, $transport);

        $result = $source->read(new SourceRequest(['url' => self::URL]));

        $this->assertTrue($result->ok);
        $this->assertCount(2, $result->data['endpoints']);
        $this->assertSame(1, $resolver->lookups);
        $this->assertCount(1, $transport->calls);
        $this->assertSame(self::URL, $transport->calls[0]['url']);
        $this->assertSame('203.0.113.10', $transport->calls[0]['address']);
    }
}
