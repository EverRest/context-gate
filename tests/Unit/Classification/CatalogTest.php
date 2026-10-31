<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Classification;

use ContextGate\Classification\Catalog;
use ContextGate\Classification\Ceiling;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CatalogTest extends TestCase
{
    public function test_a_value_outside_the_four_classes_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('orders.notes');

        new Catalog(['orders' => ['notes' => 'confidential']]);
    }

    public function test_a_non_string_class_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Catalog(['orders' => ['total' => 42]]);
    }

    public function test_returns_the_class_for_a_table_and_column(): void
    {
        $catalog = new Catalog([
            'orders' => ['status' => 'public', 'total' => 'internal'],
            'customers' => ['email' => 'personal', 'password' => 'secret'],
        ]);

        $this->assertSame(Ceiling::Internal, $catalog->classOf('orders', 'total'));
        $this->assertSame(Ceiling::Personal, $catalog->classOf('customers', 'email'));
        $this->assertSame(Ceiling::Secret, $catalog->classOf('customers', 'password'));
    }

    public function test_a_missing_column_or_table_returns_null(): void
    {
        $catalog = new Catalog(['orders' => ['status' => 'public']]);

        $this->assertNull($catalog->classOf('orders', 'notes'));
        $this->assertNull($catalog->classOf('invoices', 'status'));
    }

    public function test_loads_from_a_php_file_that_returns_the_map(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog');
        $this->assertIsString($path);
        file_put_contents($path, "<?php\n\nreturn ['orders' => ['status' => 'public']];\n");

        try {
            $catalog = Catalog::fromFile($path);
        } finally {
            unlink($path);
        }

        $this->assertSame(Ceiling::Public, $catalog->classOf('orders', 'status'));
    }
}
