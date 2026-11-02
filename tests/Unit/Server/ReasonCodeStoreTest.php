<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Server\ReasonCode;
use ContextGate\Server\ResponseBuilder;
use Laravel\Mcp\Transport\JsonRpcRequest;
use PHPUnit\Framework\TestCase;

class ReasonCodeStoreTest extends TestCase
{
    public function test_a_store_failure_is_a_failed_decision(): void
    {
        $this->assertSame('store.unavailable', ReasonCode::StoreUnavailable->value);
        $this->assertSame('failed', ReasonCode::from('store.unavailable')->decision());
    }

    public function test_a_store_failure_answers_the_generic_message_with_the_reference(): void
    {
        $request = new JsonRpcRequest(1, 'tools/call', []);

        $result = ResponseBuilder::forReason($request, ReasonCode::StoreUnavailable, 'r_x')->toArray();

        $this->assertSame('Request failed. Reference: r_x', $result['result']['content'][0]['text']);
        $this->assertTrue($result['result']['isError']);
    }
}
