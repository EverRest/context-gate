<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Runbook;

use ContextGate\Runbook\RunbookContext;
use ContextGate\Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;

/** spec 018 T010 (SC-001, FR-004, FR-005): the context offers exactly seven read checks and no write. */
class RunbookContextSurfaceTest extends TestCase
{
    /** @var array<string, array{0: string, 1: string}> method => [parameter type or '', return type] */
    private const EXPECTED = [
        'envPresent' => ['string', 'bool'],
        'configPresent' => ['string', 'bool'],
        'cacheReachable' => ['string', 'bool'],
        'queueReachable' => ['string', 'bool'],
        'migrationsPending' => ['', 'int'],
        'failedJobCount' => ['', 'int'],
        'routeExists' => ['string', 'bool'],
    ];

    public function test_the_public_methods_are_exactly_the_seven_read_checks(): void
    {
        $names = array_map(
            fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(RunbookContext::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($names);
        $expected = array_keys(self::EXPECTED);
        sort($expected);
        $this->assertSame($expected, $names);
    }

    public function test_each_method_takes_the_fr_004_parameter_and_returns_bool_or_int(): void
    {
        foreach (self::EXPECTED as $name => [$parameter, $return]) {
            $method = new ReflectionMethod(RunbookContext::class, $name);

            $types = array_map(fn ($p): string => (string) $p->getType(), $method->getParameters());
            $this->assertSame($parameter === '' ? [] : [$parameter], $types, $name);
            $this->assertSame($return, (string) $method->getReturnType(), $name);
        }
    }

    public function test_a_missing_cache_store_or_setting_is_false_not_an_exception(): void
    {
        $context = new RunbookContext();

        $this->assertFalse($context->cacheReachable('no-such-store'));
        $this->assertFalse($context->envPresent('CONTEXT_GATE_RUNBOOK_UNSET_FIXTURE'));
        $this->assertTrue($context->configPresent('app.name'));
        $this->assertFalse($context->configPresent('no.such.key'));
    }

    public function test_a_missing_failed_jobs_table_counts_as_zero(): void
    {
        $this->assertSame(0, (new RunbookContext())->failedJobCount());
    }
}
