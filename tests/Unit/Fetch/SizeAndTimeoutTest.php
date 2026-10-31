<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Fetch;

use ContextGate\Server\ReasonCode;
use ContextGate\Source\Fetch\HttpReply;
use ContextGate\Source\Fetch\PinnedClient;
use ContextGate\Source\Fetch\TransportTimeout;
use ContextGate\Source\SourceFailure;
use ContextGate\Tests\Fakes\FakeTransport;
use ContextGate\Tests\TestCase;

/** FR-009 (5 second timeout), FR-010 (2 MB read cap). */
class SizeAndTimeoutTest extends TestCase
{
    private const URL = 'https://api.example.test/openapi.json';

    public function test_response_over_2_mb_is_refused(): void
    {
        $transport = new FakeTransport(new HttpReply(200, 'application/json', str_repeat('a', 2 * 1024 * 1024 + 1)));

        try {
            (new PinnedClient($transport))->get(self::URL, '203.0.113.10');
            $this->fail('A body over 2 MB must be refused');
        } catch (SourceFailure $refused) {
            $this->assertSame(ReasonCode::SourceTooLarge, $refused->reason);
        }
    }

    public function test_transport_is_given_the_2_mb_cap_so_it_can_stop_reading(): void
    {
        $transport = new FakeTransport(new HttpReply(200, 'application/json', '{}'));

        (new PinnedClient($transport))->get(self::URL, '203.0.113.10');

        $this->assertSame(2 * 1024 * 1024, $transport->calls[0]['maxBytes']);
    }

    public function test_transport_timeout_is_refused_with_5_second_limit(): void
    {
        $transport = new FakeTransport(new TransportTimeout());

        try {
            (new PinnedClient($transport))->get(self::URL, '203.0.113.10');
            $this->fail('A timeout must be refused');
        } catch (SourceFailure $refused) {
            $this->assertSame(ReasonCode::SourceTimeout, $refused->reason);
        }

        $this->assertSame(5.0, $transport->calls[0]['timeout']);
    }
}
