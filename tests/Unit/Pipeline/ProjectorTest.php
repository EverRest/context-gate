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

    public function test_scalar_and_null_values_pass(): void
    {
        $row = ['a' => 1, 'b' => 1.5, 'c' => true, 'd' => null, 'e' => 'text'];

        $result = $this->project(new Payload([$row], ['a', 'b', 'c', 'd', 'e']));

        $this->assertSame([$row], $result->rows);
        $this->assertSame([], $result->dropped);
    }

    public function test_an_array_value_is_dropped_and_recorded_once(): void
    {
        $result = $this->project(new Payload([
            ['status' => 'paid', 'items' => [['sku' => 'A']]],
            ['status' => 'open', 'items' => ['x' => 1]],
        ], ['status', 'items']));

        $this->assertSame([['status' => 'paid'], ['status' => 'open']], $result->rows);
        $this->assertSame([['field' => 'items', 'reason' => 'field.not_scalar']], $result->dropped);
    }

    private function project(Payload $payload): Payload
    {
        return (new Projector())->handle($payload, static fn (Payload $next): Payload => $next);
    }
}
