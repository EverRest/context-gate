<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Fetch;

use ContextGate\Source\Fetch\HttpReply;
use ContextGate\Source\Fetch\PinnedClient;
use ContextGate\Source\Fetch\UrlGuard;
use ContextGate\Tests\Fakes\FakeResolver;
use ContextGate\Tests\Fakes\FakeTransport;
use ContextGate\Tests\TestCase;

/** FR-007, research R2 rebinding test: the address checked is the address connected to. */
class RebindingTest extends TestCase
{
    public function test_connects_to_the_checked_address_and_never_resolves_again(): void
    {
        $resolver = new FakeResolver([['203.0.113.10'], ['127.0.0.1']]);
        $transport = new FakeTransport(new HttpReply(200, 'application/json', []));
        $url = 'https://api.example.test/openapi.json';

        $address = (new UrlGuard(['api.example.test'], $resolver))->check($url);
        (new PinnedClient($transport))->get($url, $address);

        $this->assertSame(1, $resolver->lookups);
        $this->assertSame('203.0.113.10', $transport->calls[0]['address']);
        $this->assertNotContains('127.0.0.1', array_column($transport->calls, 'address'));
    }
}
