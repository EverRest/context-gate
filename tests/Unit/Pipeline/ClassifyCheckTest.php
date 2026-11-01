<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Pipeline;

use ContextGate\Classification\Catalog;
use ContextGate\Classification\Ceiling;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;
use ContextGate\Pipeline\ClassifyCheck;
use ContextGate\Pipeline\Payload;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

class ClassifyCheckTest extends TestCase
{
    public function test_a_field_without_a_catalog_class_is_dropped_and_recorded(): void
    {
        $result = $this->check(new Payload([['status' => 'paid', 'notes' => 'a note']], ['status', 'notes']), $this->plain());

        $this->assertSame(['status'], $result->fields);
        $this->assertSame([['field' => 'notes', 'reason' => 'field.unclassified']], $result->dropped);
    }

    public function test_a_row_field_the_contract_does_not_list_is_dropped_and_recorded_once(): void
    {
        $result = $this->check(
            new Payload([['status' => 'paid', 'user_id' => 7], ['status' => 'open', 'user_id' => 8]], ['status']),
            ['status' => Field::plain()],
        );

        $this->assertSame(['status'], $result->fields);
        $this->assertSame([['field' => 'user_id', 'reason' => 'field.unlisted']], $result->dropped);
    }

    public function test_a_row_key_that_is_not_an_identifier_is_never_recorded_by_name(): void
    {
        // A handler may key a row by a value, so an email as a key must not reach the receipt.
        $result = $this->check(
            new Payload([['status' => 'paid', 'jane@example.invalid' => 1]], ['status']),
            ['status' => Field::plain()],
        );

        $this->assertSame([['field' => '*', 'reason' => 'field.unlisted']], $result->dropped);
        $this->assertStringNotContainsString('jane@example.invalid', (string) json_encode($result->dropped));
    }

    public function test_unlisted_names_are_capped_per_call_and_the_rest_are_counted_as_a_placeholder(): void
    {
        $row = [];

        for ($i = 0; $i < 60; $i++) {
            $row["extra_{$i}"] = 1;
        }

        $result = $this->check(new Payload([$row], []), []);
        $unlisted = array_values(array_filter($result->dropped, static fn (array $entry): bool => $entry['reason'] === 'field.unlisted'));

        $this->assertCount(51, $unlisted);
        $this->assertSame('extra_0', $unlisted[0]['field']);
        $this->assertSame('*', $unlisted[50]['field']);
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

    public function test_a_field_without_a_form_is_dropped_as_not_ready(): void
    {
        $result = $this->check(new Payload([['status' => 'paid']], ['status']), []);

        $this->assertSame([], $result->fields);
        $this->assertSame([['field' => 'status', 'reason' => 'field.form_not_ready']], $result->dropped);
    }

    public function test_a_pseudonym_on_a_personal_field_is_kept_at_the_personal_ceiling(): void
    {
        $result = $this->check(
            new Payload([['email' => 'a@example.invalid']], ['email']),
            ['email' => Field::pseudonym('cust')],
            Ceiling::Personal,
        );

        $this->assertSame(['email'], $result->fields);
        $this->assertSame([], $result->dropped);
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

    public function test_a_personal_pseudonym_field_is_not_dropped_by_the_ceiling(): void
    {
        $result = $this->check(
            new Payload([['email' => 'a@example.invalid']], ['email']),
            ['email' => Field::pseudonym('cust')],
        );

        $this->assertSame(['email'], $result->fields);
        $this->assertSame([], $result->dropped);
    }

    public function test_a_relation_whose_parent_class_is_above_the_ceiling_is_dropped(): void
    {
        $result = $this->check(
            new Payload([['email' => 1]], ['email']),
            ['email' => Field::relation(ClassifyCustomerContract::class)],
            Ceiling::Internal,
        );

        $this->assertSame([], $result->fields);
        $this->assertSame([['field' => 'email', 'reason' => 'ceiling.exceeded']], $result->dropped);
    }

    public function test_a_relation_whose_parent_class_is_at_the_ceiling_is_kept(): void
    {
        $result = $this->check(
            new Payload([['email' => 1]], ['email']),
            ['email' => Field::relation(ClassifyCustomerContract::class)],
            Ceiling::Personal,
        );

        $this->assertSame(['email'], $result->fields);
        $this->assertSame([], $result->dropped);
    }

    public function test_a_relation_field_is_kept_and_checked_by_its_parent_class(): void
    {
        $result = $this->check(
            new Payload([['customer_id' => 1]], ['customer_id']),
            ['customer_id' => Field::relation(ClassifyCustomerContract::class)],
        );

        $this->assertSame(['customer_id'], $result->fields);
        $this->assertSame([], $result->dropped);
    }

    public function test_a_plain_personal_field_is_dropped_at_an_internal_ceiling(): void
    {
        $result = $this->check(
            new Payload([['email' => 'a@example.invalid']], ['email']),
            ['email' => Field::plain()],
        );

        $this->assertSame([], $result->fields);
        $this->assertSame([['field' => 'email', 'reason' => 'ceiling.exceeded']], $result->dropped);
    }

    /** @return array<string, Field> */
    private function plain(): array
    {
        return ['status' => Field::plain(), 'email' => Field::plain(), 'notes' => Field::plain()];
    }

    /** @param array<string, Field> $forms */
    private function check(Payload $payload, array $forms, Ceiling $ceiling = Ceiling::Internal): Payload
    {
        $catalog = new Catalog(['orders' => ['status' => 'public', 'email' => 'personal', 'customer_id' => 'internal']]);

        return (new ClassifyCheck($catalog, $ceiling, 'orders', $forms))
            ->handle($payload, static fn (Payload $next): Payload => $next);
    }
}

class ClassifyCustomerContract extends ContextContract
{
    public function fields(): array
    {
        return ['email' => Field::plain()];
    }

    public function model(): string
    {
        return ClassifyCustomerModel::class;
    }
}

class ClassifyCustomerModel extends Model
{
    protected $table = 'customers';
}
