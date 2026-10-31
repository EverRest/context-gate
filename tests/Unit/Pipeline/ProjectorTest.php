<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Pipeline;

use ContextGate\Pipeline\Payload;
use ContextGate\Pipeline\Projector;
use PHPUnit\Framework\TestCase;

class ProjectorTest extends TestCase
{
    public function test_only_contract_fields_survive(): void
    {
        $result = $this->project(new Payload([['status' => 'paid', 'total' => 10, 'notes' => 'a note']], ['status', 'total']));

        $this->assertSame([['status' => 'paid', 'total' => 10]], $result->rows);
    }

    public function test_order_follows_the_contract_not_the_row(): void
    {
        $result = $this->project(new Payload([['total' => 10, 'status' => 'paid']], ['status', 'total']));

        $this->assertSame(['status', 'total'], array_keys($result->rows[0]));
    }

    public function test_a_field_missing_from_the_row_is_omitted_without_error(): void
    {
        $result = $this->project(new Payload([['status' => 'paid']], ['status', 'total']));

        $this->assertSame([['status' => 'paid']], $result->rows);
    }

    private function project(Payload $payload): Payload
    {
        return (new Projector())->handle($payload, static fn (Payload $next): Payload => $next);
    }
}
