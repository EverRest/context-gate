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

    public function test_response_over_2_mb_stops_reading_and_is_refused(): void
    {
        $chunksRead = 0;
        $chunks = (function () use (&$chunksRead): \Generator {
            for ($i = 0; $i < 10; $i++) {
                $chunksRead++;

                yield str_repeat('a', 1024 * 1024);
            }
        })();
        $transport = new FakeTransport(new HttpReply(200, 'application/json', $chunks));

        try {
            (new PinnedClient($transport))->get(self::URL, '203.0.113.10');
            $this->fail('A body over 2 MB must be refused');
        } catch (SourceFailure $refused) {
            $this->assertSame(ReasonCode::SourceTooLarge, $refused->reason);
        }

        $this->assertLessThan(10, $chunksRead, 'Reading must stop at the cap');
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
