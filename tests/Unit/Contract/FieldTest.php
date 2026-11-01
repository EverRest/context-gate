<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Contract;

use ContextGate\Classification\Ceiling;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

class FieldTest extends TestCase
{
    public function test_relation_accepts_a_contract_class_name(): void
    {
        $field = Field::relation(FieldTestCustomerContract::class);

        $this->assertSame(Field::RELATION, $field->form);
        $this->assertSame(FieldTestCustomerContract::class, $field->options['contract']);
    }

    public function test_relation_refuses_a_class_that_is_not_a_contract(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Field::relation(stdClass::class);
    }

    public function test_each_factory_returns_its_form(): void
    {
        $this->assertSame(Field::PSEUDONYM, Field::pseudonym('USR')->form);
        $this->assertSame(Field::BUCKET, Field::bucket([0, 100, 1000])->form);
        $this->assertSame(Field::DATE, Field::date('month')->form);
        $this->assertSame(Field::AGGREGATE, Field::aggregate()->form);
        $this->assertSame(Field::TEXT, Field::text()->form);
        $this->assertSame(Field::PLAIN, Field::plain()->form);
    }

    public function test_plain_is_refused_for_personal(): void
    {
        $this->assertTrue(Field::plain()->allows(Ceiling::Public));
        $this->assertTrue(Field::plain()->allows(Ceiling::Internal));
        $this->assertFalse(Field::plain()->allows(Ceiling::Personal));
    }

    public function test_pseudonym_is_for_personal_and_internal_only(): void
    {
        $field = Field::pseudonym('USR');

        $this->assertTrue($field->allows(Ceiling::Personal));
        $this->assertTrue($field->allows(Ceiling::Internal));
        $this->assertFalse($field->allows(Ceiling::Public));
    }

    public function test_text_is_for_internal_and_public_only(): void
    {
        $field = Field::text();

        $this->assertTrue($field->allows(Ceiling::Public));
        $this->assertTrue($field->allows(Ceiling::Internal));
        $this->assertFalse($field->allows(Ceiling::Personal));
    }

    public function test_no_form_allows_secret(): void
    {
        $fields = [
            Field::plain(),
            Field::pseudonym('USR'),
            Field::bucket([0, 10]),
            Field::date('month'),
            Field::aggregate(),
            Field::text(),
            Field::relation(FieldTestCustomerContract::class),
        ];

        foreach ($fields as $field) {
            $this->assertFalse($field->allows(Ceiling::Secret), $field->form);
        }
    }
}

final class FieldTestCustomerContract extends ContextContract
{
    public function model(): string
    {
        return stdClass::class;
    }

    public function fields(): array
    {
        return [];
    }
}
