<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Server\Receipt;
use ContextGate\Server\ReasonCode;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Log;

/** spec 010 FR-013, SC-004: every receipt carries the role name, or none. */
class ReceiptRoleTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->lines[] = $line;
        });
    }

    /** @return array<string, mixed> */
    private function lastRecord(): array
    {
        return json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_a_receipt_without_a_role_records_none(): void
    {
        Receipt::write('token:1', null, ReasonCode::RoleUnknown);

        $this->assertSame('none', $this->lastRecord()['role']);
    }

    public function test_a_receipt_records_the_role_name(): void
    {
        Receipt::write('token:1', 'echo', ReasonCode::PolicyHidden, role: 'support');

        $this->assertSame('support', $this->lastRecord()['role']);
    }
}
