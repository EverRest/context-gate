<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Pipeline;

use ContextGate\Classification\Catalog;
use ContextGate\Classification\Ceiling;
use ContextGate\Contract\Field;
use ContextGate\Pipeline\ClassifyCheck;
use ContextGate\Pipeline\Payload;
use PHPUnit\Framework\TestCase;

class ClassifyCheckTest extends TestCase
{
    public function test_a_field_without_a_catalog_class_is_dropped_and_recorded(): void
    {
        $result = $this->check(new Payload([['status' => 'paid', 'notes' => 'a note']], ['status', 'notes']), $this->plain());

        $this->assertSame(['status'], $result->fields);
        $this->assertSame([['field' => 'notes', 'reason' => 'field.unclassified']], $result->dropped);
    }

    public function test_a_field_above_the_caller_ceiling_is_dropped_and_recorded(): void
    {
        $result = $this->check(new Payload([['status' => 'paid', 'email' => 'a@example.invalid']], ['status', 'email']), $this->plain());

        $this->assertSame(['status'], $result->fields);
        $this->assertSame([['field' => 'email', 'reason' => 'ceiling.exceeded']], $result->dropped);
    }

    public function test_a_plain_or_text_field_within_the_ceiling_is_kept(): void
    {
        $result = $this->check(new Payload([['status' => 'paid']], ['status']), ['status' => Field::text()]);

        $this->assertSame(['status'], $result->fields);
        $this->assertSame([], $result->dropped);
    }

    public function test_a_form_that_is_not_ready_is_dropped_and_never_emitted_raw(): void
    {
        $result = $this->check(new Payload([['status' => 'paid']], ['status']), ['status' => Field::date('day')]);

        $this->assertSame([], $result->fields);
        $this->assertSame([['field' => 'status', 'reason' => 'field.form_not_ready']], $result->dropped);
    }

    public function test_a_pseudonym_on_a_personal_field_is_dropped_until_the_transform_exists(): void
    {
        $result = $this->check(
            new Payload([['email' => 'a@example.invalid']], ['email']),
            ['email' => Field::pseudonym('cust')],
            Ceiling::Personal,
        );

        $this->assertSame([], $result->fields);
        $this->assertSame([['field' => 'email', 'reason' => 'field.form_not_ready']], $result->dropped);
    }

    public function test_a_plain_form_on_a_personal_field_is_dropped_even_when_the_ceiling_allows_it(): void
    {
        $result = $this->check(
            new Payload([['email' => 'a@example.invalid']], ['email']),
            ['email' => Field::plain()],
            Ceiling::Personal,
        );

        $this->assertSame([], $result->fields);
        $this->assertSame([['field' => 'email', 'reason' => 'field.form_not_allowed']], $result->dropped);
    }

    /** @return array<string, Field> */
    private function plain(): array
    {
        return ['status' => Field::plain(), 'email' => Field::plain(), 'notes' => Field::plain()];
    }

    /** @param array<string, Field> $forms */
    private function check(Payload $payload, array $forms, Ceiling $ceiling = Ceiling::Internal): Payload
    {
        $catalog = new Catalog(['orders' => ['status' => 'public', 'email' => 'personal']]);

        return (new ClassifyCheck($catalog, $ceiling, 'orders', $forms))
            ->handle($payload, static fn (Payload $next): Payload => $next);
    }
}
