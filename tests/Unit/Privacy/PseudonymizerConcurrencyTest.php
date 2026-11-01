<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Privacy;

use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

class PseudonymizerConcurrencyTest extends TestCase
{
    protected function tearDown(): void
    {
        Pseudonymizer::usePersistentSessions(false);
        Pseudonymizer::flushSessions();
        parent::tearDown();
    }

    public function test_the_load_modify_save_of_a_day_scope_runs_under_the_scope_lock(): void
    {
        $probe = new class () extends Pseudonymizer {
            public ?bool $lockWasFree = null;

            protected function digest(string $key, string $table, string $id): string
            {
                $competing = Cache::lock('context-gate:pseudonym:lock:day:alice:tool', 5);
                $this->lockWasFree = $competing->get();

                return parent::digest($key, $table, $id);
            }
        };

        $pseudonym = $probe->pseudonym('cus', 'customers', '1', 'day:tool', 'alice');

        $this->assertFalse($probe->lockWasFree);
        $this->assertSame(['table' => 'customers', 'id' => '1'], $probe->resolve($pseudonym, 'day:tool', 'alice'));
        $this->assertTrue(Cache::lock('context-gate:pseudonym:lock:day:alice:tool', 5)->get(), 'lock released afterwards');
    }

    public function test_persistent_sessions_resolve_in_a_new_process(): void
    {
        Pseudonymizer::usePersistentSessions(true, 3600);
        $scope = 'session:'.uniqid('', true);
        $pseudonym = (new Pseudonymizer())->pseudonym('cus', 'customers', '7', $scope);

        Pseudonymizer::flushSessions(); // a new PHP process starts with an empty static map

        $this->assertSame(['table' => 'customers', 'id' => '7'], (new Pseudonymizer())->resolve($pseudonym, $scope));
        $this->assertSame($pseudonym, (new Pseudonymizer())->pseudonym('cus', 'customers', '7', $scope));
    }

    public function test_memory_sessions_do_not_survive_a_flush_and_never_touch_the_cache(): void
    {
        $scope = 'session:'.uniqid('', true);
        $pseudonym = (new Pseudonymizer())->pseudonym('cus', 'customers', '7', $scope);

        $this->assertFalse(Cache::has('context-gate:pseudonym:'.$scope));
        $this->assertNotNull((new Pseudonymizer())->resolve($pseudonym, $scope));

        Pseudonymizer::flushSessions();

        $this->assertNull((new Pseudonymizer())->resolve($pseudonym, $scope));
    }

    public function test_persistent_sessions_expire_after_the_ttl(): void
    {
        Pseudonymizer::usePersistentSessions(true, 60);
        $scope = 'session:'.uniqid('', true);
        $pseudonym = (new Pseudonymizer())->pseudonym('cus', 'customers', '7', $scope);

        $this->travel(61)->seconds();

        $this->assertNull((new Pseudonymizer())->resolve($pseudonym, $scope));
    }

    public function test_memory_sessions_are_bounded_and_the_oldest_is_dropped(): void
    {
        $pseudonymizer = new Pseudonymizer();
        $first = $pseudonymizer->pseudonym('cus', 'customers', '1', 'session:first');

        for ($i = 0; $i < 100; $i++) {
            $pseudonymizer->pseudonym('cus', 'customers', '1', 'session:n'.$i);
        }

        $this->assertNull($pseudonymizer->resolve($first, 'session:first'));
        $this->assertNotNull($pseudonymizer->resolve($pseudonymizer->pseudonym('cus', 'customers', '1', 'session:n99'), 'session:n99'));
    }
}
