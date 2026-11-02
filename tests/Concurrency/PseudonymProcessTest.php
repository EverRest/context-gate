<?php

declare(strict_types=1);

namespace ContextGate\Tests\Concurrency;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pseudonym issue and resolve across real processes sharing one file cache store (opt-in, see ConcurrencyTestCase).
 * A session scope that is cache-persisted must also resolve in a later process: that is the PHP-FPM case, where each
 * request is a new process. This is checked without HTTP.
 */
#[Group('concurrency')]
class PseudonymProcessTest extends ConcurrencyTestCase
{
    private const WORKERS = 8;

    private const MINTS = 5;

    public function test_concurrent_processes_mint_one_pseudonym_for_one_value_and_store_one_entry(): void
    {
        $jobs = array_fill(0, self::WORKERS, ['pseudonym-mint', 'day:tool', 'alice', '42', (string) self::MINTS]);

        $results = $this->runWorkers($jobs);
        $issued = array_unique(array_merge(...array_column($results, 'pseudonyms')));

        $this->assertCount(1, $issued, 'every worker must get the same pseudonym: '.implode(', ', $issued));

        [$inspect] = $this->runWorkers([['pseudonym-inspect', 'day:alice:tool']]);

        $this->assertSame(1, $inspect['entries'], 'the reverse map must hold one entry for the one value');
    }

    /**
     * SC-003: workers mint for overlapping record batches in one scope. Every worker gets the same pseudonym for the
     * same record, the reverse map holds one entry per record, and every issued pseudonym resolves in a fresh process.
     *
     * @return iterable<string, array{string, string, string}> scope as given, caller, scope as stored
     */
    public static function scopes(): iterable
    {
        yield 'day' => ['day:tool', 'alice', 'day:alice:tool'];
        yield 'week' => ['week:tool', 'alice', 'week:alice:tool'];
        yield 'persistent session' => ['session:overlap', '-', 'session:overlap'];
    }

    #[DataProvider('scopes')]
    public function test_overlapping_batches_give_one_pseudonym_per_record(string $scope, string $caller, string $stored): void
    {
        // Each worker covers ten records; neighbours overlap by seven, so most records are asked for by several workers.
        $jobs = [];

        for ($worker = 0; $worker < self::WORKERS; $worker++) {
            $jobs[] = ['pseudonym-batch', $scope, $caller, (string) ($worker * 3), '10'];
        }

        $byRecord = [];

        foreach ($this->runWorkers($jobs) as $result) {
            foreach ($result['pseudonyms'] as $record => $pseudonym) {
                $byRecord[$record][] = $pseudonym;
            }
        }

        $issued = [];

        foreach ($byRecord as $record => $values) {
            $this->assertCount(1, array_unique($values), "record {$record} got more than one pseudonym");
            $issued[$record] = $values[0];
        }

        $this->assertCount(31, $issued, 'the workers cover records r0 to r30');
        $this->assertCount(count($issued), array_unique($issued), 'two records share a pseudonym');

        [$inspect] = $this->runWorkers([['pseudonym-inspect', $stored]]);
        $this->assertSame(count($issued), $inspect['entries'], 'the reverse map holds one entry per record');

        [$resolved] = $this->runWorkers([['pseudonym-resolve-all', $scope, $caller, implode(',', array_values($issued))]]);

        foreach ($issued as $record => $pseudonym) {
            $this->assertSame(['table' => 'customers', 'id' => (string) $record], $resolved['records'][$pseudonym] ?? null, "pseudonym for {$record} must resolve");
        }
    }

    public function test_a_persisted_session_pseudonym_resolves_and_repeats_in_a_later_process(): void
    {
        $scope = 'session:'.bin2hex(random_bytes(4));

        [$first] = $this->runWorkers([['pseudonym-mint', $scope, '-', '7', '1']]);
        $pseudonym = $first['pseudonyms'][0];

        [$resolved, $again] = $this->runWorkers([
            ['pseudonym-resolve', $scope, '-', $pseudonym],
            ['pseudonym-mint', $scope, '-', '7', '1'],
        ]);

        $this->assertSame(['table' => 'customers', 'id' => '7'], $resolved['record']);
        $this->assertSame([$pseudonym], $again['pseudonyms']);
    }
}
