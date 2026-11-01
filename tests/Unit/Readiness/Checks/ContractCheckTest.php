<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness\Checks;

use ContextGate\Classification\Catalog;
use ContextGate\Readiness\CheckStatus;
use ContextGate\Readiness\Checks\ContractCheck;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Tests\Fixtures\DepthRelationProbeContract;
use ContextGate\Tests\Fixtures\RelationProbeContract;
use ContextGate\Tests\TestCase;

/** T022, spec 002 FR-004, FR-007 and FR-011: no secret field in a contract, relations go one level only. */
class ContractCheckTest extends TestCase
{
    public function test_a_contract_listing_a_secret_field_fails(): void
    {
        $catalog = new Catalog([
            'orders' => ['status' => 'public', 'customer_id' => 'secret'],
            'customers' => ['name' => 'public'],
        ]);
        $ctx = new ReadinessContext(
            ['tools' => ['probe' => ['contract' => RelationProbeContract::class]]],
            $catalog,
            [],
        );

        $result = (new ContractCheck())->run($ctx);

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame('tools.probe.contract', $result->subject);
        $this->assertStringContainsString('secret', $result->message);
    }

    public function test_a_relation_of_depth_two_fails(): void
    {
        $catalog = new Catalog([
            'orders' => ['status' => 'public', 'customer_id' => 'internal'],
            'customers' => ['name' => 'public'],
        ]);
        $ctx = new ReadinessContext(
            ['tools' => ['probe' => ['contract' => DepthRelationProbeContract::class]]],
            $catalog,
            [],
        );

        $result = (new ContractCheck())->run($ctx);

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame('tools.probe.contract', $result->subject);
        $this->assertStringContainsString('contract.depth', $result->message);
    }
}
