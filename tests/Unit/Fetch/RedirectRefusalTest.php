<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Fetch;

use ContextGate\Server\ReasonCode;
use ContextGate\Source\Fetch\HttpReply;
use ContextGate\Source\Fetch\PinnedClient;
use ContextGate\Source\SourceFailure;
use ContextGate\Tests\Fakes\FakeTransport;
use ContextGate\Tests\TestCase;

/** FR-008: a redirect is an error, and the redirect target is never requested. */
class RedirectRefusalTest extends TestCase
{
    public function test_302_is_refused_and_not_followed(): void
    {
        $transport = new FakeTransport(new HttpReply(302, 'text/html', []));

        try {
            (new PinnedClient($transport))->get('https://api.example.test/openapi.json', '203.0.113.10');
            $this->fail('A redirect must be refused');
        } catch (SourceFailure $refused) {
            $this->assertSame(ReasonCode::SourceRedirect, $refused->reason);
        }

        $this->assertCount(1, $transport->calls);
    }
}
