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

    /** The record identifiers are read before a projection drops the id column, so they are passed by row position. */
    public function test_pseudonym_uses_the_identifier_passed_by_row_position_when_the_row_has_none(): void
    {
        $scope = 'session:'.uniqid('', true);
        $expected = (new Pseudonymizer())->pseudonym('USR', 'users', '42', $scope);
        $transformer = new Transformer(new Pseudonymizer(), 'users', $scope, ['email' => Field::pseudonym('USR')], 'id', null, [0 => 42]);

        $result = $transformer->handle(new Payload([['email' => 'jane@example.test']]), static fn (Payload $next): Payload => $next);

        $this->assertSame($expected, $result->rows[0]['email']);
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

        $month = $this->transform([['at' => '2026-10-31']], ['at' => Field::date('month')], $scope);
        $year = $this->transform([['at' => '2026-10-31']], ['at' => Field::date('year')], $scope);

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

    public function test_a_null_in_a_bucket_or_date_column_passes_through(): void
    {
        $result = $this->transform(
            [['id' => 1, 'age' => 40, 'born' => '1980-05-06'], ['id' => 2, 'age' => null, 'born' => null]],
            ['age' => Field::bucket([18, 65]), 'born' => Field::date('year')],
            'session:'.uniqid('', true),
        );

        $this->assertSame('18-65', $result->rows[0]['age']);
        $this->assertSame('1980', $result->rows[0]['born']);
        $this->assertNull($result->rows[1]['age']);
        $this->assertNull($result->rows[1]['born']);
    }

    public function test_an_aggregate_never_leaks_the_raw_value_the_row_holds(): void
    {
        $result = $this->transform(
            [['id' => 1, 'total' => 'RAW-SECRET-1'], ['id' => 2, 'total' => 'RAW-SECRET-2']],
            ['total' => Field::aggregate()],
            'session:'.uniqid('', true),
        );

        $this->assertSame(2, $result->rows[0]['total']);
        $this->assertSame(2, $result->rows[1]['total']);
    }

    public function test_a_pseudonym_column_is_issued_with_one_batch_call_for_all_rows(): void
    {
        $probe = new class () extends Pseudonymizer {
            public int $batches = 0;

            public int $singles = 0;

            public function pseudonyms(string $prefix, string $table, array $ids, string $scope, ?string $caller = null): array
            {
                $this->batches++;

                return parent::pseudonyms($prefix, $table, $ids, $scope, $caller);
            }

            public function pseudonym(string $prefix, string $table, string $id, string $scope, ?string $caller = null): string
            {
                $this->singles++;

                return parent::pseudonym($prefix, $table, $id, $scope, $caller);
            }
        };
        $rows = array_map(static fn (int $i): array => ['id' => $i % 50, 'email' => "u{$i}@example.test"], range(1, 200));
        $scope = 'session:'.uniqid('', true);

        $result = (new Transformer($probe, 'users', $scope, ['email' => Field::pseudonym('USR')]))
            ->handle(new Payload($rows), static fn (Payload $next): Payload => $next);

        $this->assertSame(1, $probe->batches);
        $this->assertSame(0, $probe->singles);
        $this->assertSame((new Pseudonymizer())->pseudonym('USR', 'users', '7', $scope), $result->rows[6]['email']);
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
