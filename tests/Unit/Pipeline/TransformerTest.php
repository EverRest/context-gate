<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Pipeline;

use ContextGate\Contract\Field;
use ContextGate\Pipeline\Payload;
use ContextGate\Pipeline\Transformer;
use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Tests\TestCase;
use RuntimeException;

class TransformerTest extends TestCase
{
    public function test_pseudonym_replaces_the_value_with_the_record_pseudonym(): void
    {
        $scope = 'session:'.uniqid('', true);
        $expected = (new Pseudonymizer())->pseudonym('USR', 'users', '42', $scope);

        $result = $this->transform(
            [['id' => 42, 'email' => 'jane@example.test']],
            ['email' => Field::pseudonym('USR')],
            $scope,
        );

        $this->assertSame($expected, $result->rows[0]['email']);
        $this->assertMatchesRegularExpression('/^USR_[0-9a-f]{10}$/', $result->rows[0]['email']);
    }

    public function test_pseudonym_without_a_record_identifier_fails_closed(): void
    {
        $this->expectException(RuntimeException::class);

        $this->transform([['email' => 'jane@example.test']], ['email' => Field::pseudonym('USR')], 'session:'.uniqid('', true));
    }

    public function test_bucket_replaces_a_number_with_its_range_label(): void
    {
        $result = $this->transform(
            [['total' => -1], ['total' => 5], ['total' => 500]],
            ['total' => Field::bucket([0, 10, 100])],
            'session:'.uniqid('', true),
        );

        $this->assertSame(['<0', '0-10', '100+'], array_column($result->rows, 'total'));
    }

    public function test_date_truncates_to_the_precision(): void
    {
        $scope = 'session:'.uniqid('', true);

        $day = $this->transform([['at' => '2026-10-31']], ['at' => Field::date('day')], $scope);
        $month = $this->transform([['at' => '2026-10-31']], ['at' => Field::date('month')], $scope);
        $year = $this->transform([['at' => '2026-10-31']], ['at' => Field::date('year')], $scope);

        $this->assertSame('2026-10-31', $day->rows[0]['at']);
        $this->assertSame('2026-10', $month->rows[0]['at']);
        $this->assertSame('2026', $year->rows[0]['at']);
    }

    public function test_aggregate_is_the_row_count_of_the_payload(): void
    {
        $result = $this->transform(
            [['id' => 1], ['id' => 2], ['id' => 3]],
            ['orders' => Field::aggregate()],
            'session:'.uniqid('', true),
        );

        $this->assertSame([3, 3, 3], array_column($result->rows, 'orders'));
    }

    public function test_plain_columns_pass_through_unchanged(): void
    {
        $result = $this->transform([['status' => 'paid']], ['status' => Field::plain()], 'session:'.uniqid('', true));

        $this->assertSame('paid', $result->rows[0]['status']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, Field> $forms
     */
    private function transform(array $rows, array $forms, string $scope): Payload
    {
        $transformer = new Transformer(new Pseudonymizer(), 'users', $scope, $forms);

        return $transformer->handle(new Payload($rows), static fn (Payload $next): Payload => $next);
    }
}
