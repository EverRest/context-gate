<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness;

use ContextGate\Readiness\Check;
use ContextGate\Readiness\CheckRegistry;
use PHPUnit\Framework\TestCase;

class CheckRegistryTest extends TestCase
{
    /** @return list<string> class names of every PHP file in src/Readiness/Checks */
    private function checkClassesOnDisk(): array
    {
        $files = glob(dirname(__DIR__, 3).'/src/Readiness/Checks/*.php') ?: [];

        return array_map(
            static fn (string $file): string => 'ContextGate\\Readiness\\Checks\\'.basename($file, '.php'),
            $files,
        );
    }

    public function test_all_returns_every_check_class_in_the_checks_folder(): void
    {
        $registered = CheckRegistry::all();
        $onDisk = $this->checkClassesOnDisk();

        sort($registered);
        sort($onDisk);

        $this->assertSame($onDisk, $registered);
    }

    public function test_all_has_no_duplicates(): void
    {
        $registered = CheckRegistry::all();

        $this->assertSame(array_values(array_unique($registered)), $registered);
    }

    public function test_every_registered_class_implements_check(): void
    {
        $notChecks = array_filter(
            CheckRegistry::all(),
            static fn (string $class): bool => !class_exists($class) || !is_subclass_of($class, Check::class),
        );

        $this->assertSame([], array_values($notChecks));
    }
}
