<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness;

use ContextGate\Classification\Catalog;
use ContextGate\Readiness\Audit;
use ContextGate\Tests\Fixtures\CustomerContract;
use ContextGate\Tests\Fixtures\EchoTool;
use PHPUnit\Framework\TestCase;

/** US1 scenario 2, FR-002: a tool that is not enabled gives an entry with only its name and enabled false. */
class AuditEntryTest extends TestCase
{
    public function test_a_tool_without_an_enabled_flag_gives_only_tool_and_enabled_false(): void
    {
        $audit = new Audit(
            ['quiet' => ['class' => EchoTool::class, 'contract' => CustomerContract::class]],
            'internal',
            new Catalog([]),
            ['tool' => 'a1b2c3', 'contract' => 'd4e5f6', 'classification' => '0a1b2c'],
        );

        $entries = $audit->entries();

        $this->assertCount(1, $entries);
        $this->assertSame(['tool' => 'quiet', 'enabled' => false], $entries[0]->toArray());
    }
}
