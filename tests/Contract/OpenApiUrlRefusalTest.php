<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Source\Fetch\HttpReply;
use ContextGate\Source\Fetch\TransportTimeout;
use ContextGate\Source\OpenApiSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\Fakes\FakeResolver;
use ContextGate\Tests\Fakes\FakeTransport;
use ContextGate\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** User Story 2 and SC-002: each refused URL returns its reason code and nothing that names the host or address. */
class OpenApiUrlRefusalTest extends TestCase
{
    private const URL = 'https://api.example.test/openapi.json';

    private const HOST = 'api.example.test';

    /** @return array<string, array{list<string>, list<string>, mixed, string, array<string, mixed>}> */
    public static function refusals(): array
    {
        return [
            'empty allowlist refuses every url' => [[], ['203.0.113.10'], null, 'egress.denied', ['url' => self::URL]],
            'host not on the allowlist' => [['other.example.test'], ['203.0.113.10'], null, 'egress.denied', ['url' => self::URL]],
            'resolves to loopback' => [[self::HOST], ['127.0.0.1'], null, 'egress.denied', ['url' => self::URL]],
            'resolves to a private address' => [[self::HOST], ['10.0.0.5'], null, 'egress.denied', ['url' => self::URL]],
            'resolves to the metadata address' => [[self::HOST], ['169.254.169.254'], null, 'egress.denied', ['url' => self::URL]],
            'one refused address refuses the whole host' => [[self::HOST], ['203.0.113.10', '10.0.0.5'], null, 'egress.denied', ['url' => self::URL]],
            'redirect is refused' => [[self::HOST], ['203.0.113.10'], new HttpReply(302, 'text/html', ''), 'source.redirect', ['url' => self::URL]],
            'timeout' => [[self::HOST], ['203.0.113.10'], new TransportTimeout(), 'source.timeout', ['url' => self::URL]],
            'document over 2 MB' => [[self::HOST], ['203.0.113.10'], new HttpReply(200, 'application/json', str_repeat(' ', 2 * 1024 * 1024 + 1)), 'source.too_large', ['url' => self::URL]],
            'non-200 status' => [[self::HOST], ['203.0.113.10'], new HttpReply(404, 'application/json', '{}'), 'source.refused', ['url' => self::URL]],
            'html content type' => [[self::HOST], ['203.0.113.10'], new HttpReply(200, 'text/html', '<html></html>'), 'source.refused', ['url' => self::URL]],
            'swagger 2.0 document' => [[self::HOST], ['203.0.113.10'], new HttpReply(200, 'application/json', '{"swagger":"2.0","paths":{}}'), 'openapi.version', ['url' => self::URL]],
            'path and url together' => [[self::HOST], ['203.0.113.10'], null, 'input.invalid', ['url' => self::URL, 'path' => 'openapi/valid-3.1.json']],
            'neither path nor url' => [[self::HOST], ['203.0.113.10'], null, 'input.invalid', []],
        ];
    }

    /**
     * @param list<string> $allowlist
     * @param list<string> $addresses
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('refusals')]
    public function test_refusal_returns_its_reason_code_and_no_address(array $allowlist, array $addresses, mixed $outcome, string $expected, array $arguments): void
    {
        $resolver = new FakeResolver([$addresses]);
        $transport = new FakeTransport($outcome ?? new HttpReply(200, 'application/json', '{}'));
        $source = new OpenApiSource(dirname(__DIR__) . '/Fixtures', ['openapi/'], $allowlist, $resolver, $transport);

        $result = $source->read(new SourceRequest($arguments));

        $this->assertFalse($result->ok);
        $reason = $result->reason?->value;
        $this->assertSame($expected, $reason);
        $serialized = (string) json_encode([$reason, $result->refusalStep, $result->data]);
        $this->assertStringNotContainsString(self::HOST, $serialized);

        foreach ($addresses as $address) {
            $this->assertStringNotContainsString($address, $serialized);
        }
    }
}
