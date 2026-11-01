<?php

declare(strict_types=1);

namespace ContextGate\Tests\Concurrency;

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
