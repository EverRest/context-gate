<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Concurrency;

use ContextGate\Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Spec 008 FR-004, SC-006 (T033): without CONTEXT_GATE_CONCURRENCY=1 every multi-process test skips in setUp, before
 * any worker starts or any temporary run directory is created.
 */
class OptInGateTest extends TestCase
{
    public function test_every_concurrency_test_skips_in_setup_without_the_opt_in(): void
    {
        if (getenv('CONTEXT_GATE_CONCURRENCY') === '1') {
            $this->markTestSkipped('The opt-in is set: this test checks the default run.');
        }

        $before = glob(sys_get_temp_dir().'/cg-conc-*') ?: [];
        $checked = 0;

        foreach ($this->classes() as $class) {
            foreach ($this->testMethods($class) as $method) {
                $test = new $class($method);

                try {
                    (new ReflectionMethod($test, 'setUp'))->invoke($test);
                    $this->fail("{$class}::{$method} ran without the opt-in");
                } catch (\Throwable $e) {
                    if ($e::class !== 'PHPUnit\Framework\SkippedWithMessageException') {
                        throw $e;
                    }

                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(0, $checked);
        $this->assertSame($before, glob(sys_get_temp_dir().'/cg-conc-*') ?: [], 'no run directory was created');
    }

    /** @return list<class-string> */
    private function classes(): array
    {
        $classes = [];

        foreach (glob(dirname(__DIR__, 2).'/Concurrency/*Test.php') ?: [] as $file) {
            /** @var class-string $class */
            $class = 'ContextGate\\Tests\\Concurrency\\'.basename($file, '.php');
            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    private function testMethods(string $class): array
    {
        /** @var list<string> $methods */
        $methods = [];

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), 'test') && $method->getDeclaringClass()->getName() === $class) {
                $methods[] = $method->getName();
            }
        }

        return $methods;
    }
}
