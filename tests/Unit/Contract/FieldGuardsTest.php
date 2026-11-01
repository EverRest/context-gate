<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Contract;

use ContextGate\Classification\Catalog;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\ContractValidator;
use ContextGate\Contract\Field;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class FieldGuardsTest extends TestCase
{
    #[DataProvider('badDatePrecisions')]
    public function test_date_rejects_a_precision_finer_than_month(string $precision): void
    {
        $this->expectException(InvalidArgumentException::class);

        Field::date($precision);
    }

    /** @return array<string, array{string}> */
    public static function badDatePrecisions(): array
    {
        return ['day' => ['day'], 'empty' => [''], 'unknown' => ['hour']];
    }

    /** @param list<mixed> $breaks */
    #[DataProvider('badBreaks')]
    public function test_bucket_rejects_breaks_that_would_reveal_the_value(array $breaks): void
    {
        $this->expectException(InvalidArgumentException::class);

        call_user_func(Field::bucket(...), $breaks);
    }

    /** @return array<string, array{list<mixed>}> */
    public static function badBreaks(): array
    {
        return [
            'empty' => [[]],
            'fine grid' => [range(0, 200000, 1)],
            'twenty-one' => [range(0, 2000, 100)],
            'descending' => [[10, 0]],
            'duplicate' => [[0, 10, 10]],
            'hairline gap' => [[1000, 1001]],
            'not numbers' => [['a', 'b']],
        ];
    }

    public function test_bucket_accepts_twenty_coarse_breaks(): void
    {
        $this->assertSame(Field::BUCKET, Field::bucket([0, 10, 100, 1000])->form);
        $this->assertSame(Field::BUCKET, Field::bucket(array_map(fn (int $i): int => 10 * 2 ** $i, range(0, 19)))->form);
    }

    public function test_violation_returns_the_message_without_throwing_and_null_when_valid(): void
    {
        $validator = new ContractValidator();
        $catalog = new Catalog(['people' => ['born' => 'personal', 'age' => 'internal']]);

        $this->assertNull($validator->violation(new GuardsOkContract(), $catalog));
        $this->assertStringContainsString('people.born', (string) $validator->violation(new GuardsDateContract(), $catalog));
    }
}

final class GuardsPerson extends Model
{
    protected $table = 'people';
}

final class GuardsOkContract extends ContextContract
{
    public function model(): string
    {
        return GuardsPerson::class;
    }

    public function fields(): array
    {
        return ['age' => Field::plain()];
    }
}

final class GuardsDateContract extends ContextContract
{
    public function model(): string
    {
        return GuardsPerson::class;
    }

    public function fields(): array
    {
        return ['born' => Field::date('month')];
    }
}
