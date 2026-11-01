<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

use Closure;
use ContextGate\Contract\Field;
use ContextGate\Privacy\Pseudonymizer;
use DateTimeImmutable;
use RuntimeException;

/**
 * Stage 4 (R1): replaces a value with its pseudonym, bucket, date or count form. Plain, text and relation
 * columns pass through unchanged; the classification and projection stages decide those.
 */
final class Transformer implements Stage
{
    /**
     * @param array<string, Field> $forms column => form
     * @param string|null $caller required by a day or week scope
     * @param array<int, int|string> $ids record identifiers by row position, for when the projection dropped the id column
     */
    public function __construct(
        private readonly Pseudonymizer $pseudonymizer,
        private readonly string $table,
        private readonly string $scope,
        private readonly array $forms,
        private readonly string $idColumn = 'id',
        private readonly ?string $caller = null,
        private readonly array $ids = [],
    ) {
    }

    public function handle(Payload $payload, Closure $next): Payload
    {
        $count = count($payload->rows);

        $payload->rows = array_map(
            fn (array $row, int $index): array => $this->row($row, $count, $index),
            $payload->rows,
            array_keys($payload->rows),
        );

        return $next($payload);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function row(array $row, int $count, int $index): array
    {
        foreach ($this->forms as $column => $field) {
            // An aggregate describes the whole payload, so it does not need the column in the row.
            if (! array_key_exists($column, $row) && $field->form !== Field::AGGREGATE) {
                continue;
            }

            $row[$column] = match ($field->form) {
                Field::PSEUDONYM => $this->pseudonym($field, $row, $index),
                Field::BUCKET => $this->bucket($field, $row[$column]),
                Field::DATE => $this->date($field, $row[$column]),
                Field::AGGREGATE => $count,
                default => $row[$column],
            };
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function pseudonym(Field $field, array $row, int $index): string
    {
        /** @var string $prefix */
        $prefix = $field->options['prefix'];
        $id = $this->ids[$index] ?? $row[$this->idColumn] ?? null;

        // Fail closed: without the record identifier there is no pseudonym, and the raw value must not leave.
        if (! is_string($id) && ! is_int($id)) {
            throw new RuntimeException('Pseudonym needs the record identifier.');
        }

        return $this->pseudonymizer->pseudonym($prefix, $this->table, (string) $id, $this->scope, $this->caller);
    }

    /** Range label over ascending breaks, e.g. "<10", "10-19", "20+". */
    private function bucket(Field $field, mixed $value): string
    {
        /** @var list<int|float> $breaks */
        $breaks = $field->options['breaks'];

        if (! is_numeric($value)) {
            throw new RuntimeException('Bucket needs a numeric value.');
        }

        $low = null;

        foreach ($breaks as $break) {
            if ((float) $value < $break) {
                return $low === null ? "<{$break}" : "{$low}-{$break}";
            }

            $low = $break;
        }

        return "{$low}+";
    }

    private function date(Field $field, mixed $value): string
    {
        /** @var string $precision */
        $precision = $field->options['precision'];

        if (! is_string($value)) {
            throw new RuntimeException('Date needs a string value.');
        }

        $format = match ($precision) {
            'year' => 'Y',
            'month' => 'Y-m',
            default => throw new RuntimeException('Date precision is not allowed.'),
        };

        return (new DateTimeImmutable($value))->format($format);
    }
}
