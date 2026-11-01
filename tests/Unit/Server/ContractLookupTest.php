<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Server\Canaries;
use ContextGate\Server\ToolRegistry;
use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\TestCase;

class ContractLookupTest extends TestCase
{
    public function test_the_contract_is_found_by_tool_name_and_only_when_it_is_a_contract_class(): void
    {
        config(['context-gate.tools' => [
            'a' => ['contract' => ProbeContract::class],
            'b' => ['contract' => self::class],
            'c' => [],
        ]]);

        $this->assertSame(ProbeContract::class, ToolRegistry::contractClass('a'));
        $this->assertInstanceOf(ProbeContract::class, ToolRegistry::contractFor('a'));
        $this->assertNull(ToolRegistry::contractClass('b'));
        $this->assertNull(ToolRegistry::contractFor('c'));
        $this->assertNull(ToolRegistry::contractFor('missing'));
    }

    public function test_the_canary_values_are_the_non_empty_strings_only(): void
    {
        config(['context-gate.canaries.values' => ['x', '', null, 5, 'y']]);

        $this->assertSame(['x', 'y'], Canaries::values());
    }
}
