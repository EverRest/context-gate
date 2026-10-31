<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Server\ReasonCode;
use PHPUnit\Framework\TestCase;

class ReasonCodeTest extends TestCase
{
    public function test_has_exactly_the_fourteen_reason_codes(): void
    {
        $values = array_map(static fn (ReasonCode $case): string => $case->value, ReasonCode::cases());

        $this->assertSame(
            ['policy.hidden', 'tool.pin_mismatch', 'input.invalid', 'policy.denied', 'handler.failed', 'pseudonym.unknown', 'pseudonym.keyfail', 'canary.hit', 'budget.session', 'budget.daily', 'rate.limited', 'source.missing', 'schema.missing', 'source.refused'],
            $values,
        );
    }

    public function test_each_code_maps_to_its_decision(): void
    {
        $this->assertSame('hidden', ReasonCode::PolicyHidden->decision());
        $this->assertSame('hidden', ReasonCode::ToolPinMismatch->decision());
        $this->assertSame('invalid', ReasonCode::InputInvalid->decision());
        $this->assertSame('denied', ReasonCode::PolicyDenied->decision());
        $this->assertSame('failed', ReasonCode::HandlerFailed->decision());
        $this->assertSame('refused', ReasonCode::PseudonymUnknown->decision());
        $this->assertSame('failed', ReasonCode::PseudonymKeyFail->decision());
        $this->assertSame('blocked', ReasonCode::CanaryHit->decision());
        $this->assertSame('refused', ReasonCode::BudgetSession->decision());
        $this->assertSame('refused', ReasonCode::BudgetDaily->decision());
        $this->assertSame('refused', ReasonCode::RateLimited->decision());
        $this->assertSame('failed', ReasonCode::SourceMissing->decision());
        $this->assertSame('failed', ReasonCode::SchemaMissing->decision());
        $this->assertSame('denied', ReasonCode::SourceRefused->decision());
    }
}
