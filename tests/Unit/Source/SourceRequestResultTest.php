<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Server\ReasonCode;
use ContextGate\Source\SourceRequest;
use ContextGate\Source\SourceResult;
use PHPUnit\Framework\TestCase;

class SourceRequestResultTest extends TestCase
{
    public function test_request_carries_its_arguments(): void
    {
        $request = new SourceRequest(['prefix' => 'orders']);

        $this->assertSame(['prefix' => 'orders'], $request->arguments);
    }

    public function test_ok_result_carries_data_and_no_refusal(): void
    {
        $result = SourceResult::ok(['routes' => []]);

        $this->assertTrue($result->ok);
        $this->assertSame(['routes' => []], $result->data);
        $this->assertNull($result->reason);
        $this->assertNull($result->refusalStep);
    }

    public function test_refused_result_carries_the_step_name_and_no_path(): void
    {
        $result = SourceResult::refused('denylist');

        $this->assertFalse($result->ok);
        $this->assertSame([], $result->data);
        $this->assertSame(ReasonCode::SourceRefused, $result->reason);
        $this->assertSame('denylist', $result->refusalStep);
    }

    public function test_failed_result_carries_only_the_reason_code(): void
    {
        $result = SourceResult::failed(ReasonCode::SchemaMissing);

        $this->assertFalse($result->ok);
        $this->assertSame([], $result->data);
        $this->assertSame(ReasonCode::SchemaMissing, $result->reason);
        $this->assertNull($result->refusalStep);
    }
}
