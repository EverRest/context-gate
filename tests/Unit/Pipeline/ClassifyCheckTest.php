<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Pipeline;

use ContextGate\Classification\Catalog;
use ContextGate\Classification\Ceiling;
use ContextGate\Pipeline\ClassifyCheck;
use ContextGate\Pipeline\Payload;
use PHPUnit\Framework\TestCase;

class ClassifyCheckTest extends TestCase
{
    public function test_a_field_without_a_catalog_class_is_dropped_and_recorded(): void
    {
        $result = $this->check(new Payload([['status' => 'paid', 'notes' => 'a note']], ['status', 'notes']));

        $this->assertSame(['status'], $result->fields);
        $this->assertSame([['field' => 'notes', 'reason' => 'field.unclassified']], $result->dropped);
    }

    public function test_a_field_above_the_caller_ceiling_is_dropped_and_recorded(): void
    {
        $result = $this->check(new Payload([['status' => 'paid', 'email' => 'a@example.invalid']], ['status', 'email']));

        $this->assertSame(['status'], $result->fields);
        $this->assertSame([['field' => 'email', 'reason' => 'ceiling.exceeded']], $result->dropped);
    }

    private function check(Payload $payload): Payload
    {
        $catalog = new Catalog(['orders' => ['status' => 'public', 'email' => 'personal']]);

        return (new ClassifyCheck($catalog, Ceiling::Internal, 'orders'))
            ->handle($payload, static fn (Payload $next): Payload => $next);
    }
}
