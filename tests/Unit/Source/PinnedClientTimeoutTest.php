<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Pipeline\Caps;
use ContextGate\Server\ReasonCode;
use ContextGate\Source\Fetch\HttpReply;
use ContextGate\Source\Fetch\PinnedClient;
use ContextGate\Source\SourceFailure;
use ContextGate\Tests\Fakes\FakeTransport;
use PHPUnit\Framework\TestCase;

/** spec 007 T030 (US4, FR-010): PinnedClient takes the effective timeout and size, clamped to their caps. */
class PinnedClientTimeoutTest extends TestCase
{
    private const URL = 'https://api.example.test/openapi.json';

    public function test_without_a_value_it_uses_the_cap(): void
    {
        $transport = new FakeTransport(new HttpReply(200, 'application/json', '{}'));

        (new PinnedClient($transport))->get(self::URL, '203.0.113.10');

        $this->assertSame(Caps::OPENAPI_TIMEOUT, $transport->calls[0]['timeout']);
        $this->assertSame(Caps::OPENAPI_BYTES, $transport->calls[0]['maxBytes']);
    }

    public function test_the_given_timeout_and_size_reach_the_transport(): void
    {
        $transport = new FakeTransport(new HttpReply(200, 'application/json', '{}'));

        (new PinnedClient($transport, 2.5, 1048576))->get(self::URL, '203.0.113.10');

        $this->assertSame(2.5, $transport->calls[0]['timeout']);
        $this->assertSame(1048576, $transport->calls[0]['maxBytes']);
    }

    public function test_a_value_above_the_cap_is_clamped_to_the_cap(): void
    {
        $transport = new FakeTransport(new HttpReply(200, 'application/json', '{}'));

        (new PinnedClient($transport, 9.0, Caps::OPENAPI_BYTES * 2))->get(self::URL, '203.0.113.10');

        $this->assertSame(Caps::OPENAPI_TIMEOUT, $transport->calls[0]['timeout']);
        $this->assertSame(Caps::OPENAPI_BYTES, $transport->calls[0]['maxBytes']);
    }

    public function test_a_body_over_the_given_size_is_refused(): void
    {
        $transport = new FakeTransport(new HttpReply(200, 'application/json', str_repeat('a', 1048577)));

        try {
            (new PinnedClient($transport, 2.5, 1048576))->get(self::URL, '203.0.113.10');
            $this->fail('A body over the effective size must be refused');
        } catch (SourceFailure $refused) {
            $this->assertSame(ReasonCode::SourceTooLarge, $refused->reason);
        }
    }
}
