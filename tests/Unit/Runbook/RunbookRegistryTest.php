<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Runbook;

use ContextGate\Runbook\RunbookRegistry;
use ContextGate\Tests\Fixtures\Runbook\PassingRunbook;
use ContextGate\Tests\TestCase;

/** spec 018 T014 (FR-002, FR-018, R8): names, classes and declared order of the `runbooks` configuration. */
class RunbookRegistryTest extends TestCase
{
    private const CLASS_NAME = PassingRunbook::class;

    /** @return list<string> */
    private function keysOf(RunbookRegistry $registry): array
    {
        return array_column($registry->problems(), 'key');
    }

    public function test_a_valid_map_has_no_problems(): void
    {
        $this->assertSame([], (new RunbookRegistry(['passing' => self::CLASS_NAME]))->problems());
    }

    public function test_a_name_outside_the_pattern_is_reported_with_its_key(): void
    {
        $this->assertSame(['Bad Name'], $this->keysOf(new RunbookRegistry(['Bad Name' => self::CLASS_NAME])));
    }

    public function test_a_case_only_duplicate_is_reported_for_both_keys(): void
    {
        $registry = new RunbookRegistry(['disk' => self::CLASS_NAME, 'Disk' => self::CLASS_NAME]);
        $duplicates = array_filter($registry->problems(), fn (array $p): bool => str_contains($p['message'], 'duplicate'));

        $this->assertSame(['disk', 'Disk'], array_column($duplicates, 'key'));
    }

    public function test_a_class_that_does_not_exist_is_reported_with_its_key(): void
    {
        $this->assertSame(['disk'], $this->keysOf(new RunbookRegistry(['disk' => 'App\\Runbooks\\MissingRunbook'])));
    }

    public function test_all_keeps_declared_order_and_maps_each_name_to_its_class(): void
    {
        $registry = new RunbookRegistry(['zeta' => self::CLASS_NAME, 'alpha' => self::CLASS_NAME]);

        $this->assertSame(['zeta' => self::CLASS_NAME, 'alpha' => self::CLASS_NAME], $registry->all());
        $this->assertSame(['zeta', 'alpha'], array_keys($registry->all()));
    }

    public function test_an_unregistered_name_returns_null(): void
    {
        $this->assertNull((new RunbookRegistry(['passing' => self::CLASS_NAME]))->find('nope'));
    }

    public function test_find_resolves_a_registered_name_to_its_runbook(): void
    {
        $runbook = (new RunbookRegistry(['passing' => self::CLASS_NAME]))->find('passing');

        $this->assertInstanceOf(PassingRunbook::class, $runbook);
    }

    public function test_from_config_reads_the_runbooks_key(): void
    {
        config()->set('context-gate.runbooks', ['passing' => self::CLASS_NAME]);

        $this->assertSame(['passing' => self::CLASS_NAME], RunbookRegistry::fromConfig()->all());
    }
}
