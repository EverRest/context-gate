<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Server\GateServer;
use ContextGate\Tests\Fixtures\CustomerContract;
use ContextGate\Tests\TestCase;

/** A tool whose contract fails the load rules at boot is listed with its tool and rule; boot writes that line to stderr. */
class InvalidContractAtBootTest extends TestCase
{
    use GateCallHarness;

    public function test_an_invalid_contract_is_listed_with_the_tool_and_the_rule(): void
    {
        $this->configureTool(CustomerContract::class, ['customers' => ['customer' => 'public']]);

        $refusals = GateServer::contractRefusals();

        $this->assertArrayHasKey('probe', $refusals);
        $this->assertStringContainsString('customers.email', $refusals['probe']);
    }
}
