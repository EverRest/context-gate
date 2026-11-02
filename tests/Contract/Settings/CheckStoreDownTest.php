<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Tests\Fakes\FakeRedisCache;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/**
 * Spec 008 US4 #4 (T021, verification of the store probe in gate:check): a store that does not answer fails the check,
 * names cache_store and prints the exception class only. No real Redis is contacted: the probe fake throws.
 */
class CheckStoreDownTest extends TestCase
{
    public function test_a_store_that_does_not_answer_fails_gate_check_with_the_class_only(): void
    {
        TokenFactory::migrate();
        FakeRedisCache::install(new \RuntimeException('connect tcp://10.0.0.1:6379 password=FAKE_FIXTURE_ONLY'));

        $this->assertSame(1, Artisan::call('gate:check'));

        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/FAIL context-gate\.cache_store: store \[gate\] \(driver redis\) did not answer the probe \(RuntimeException\)/', $output);
        $this->assertStringNotContainsString('10.0.0.1', $output);
        $this->assertStringNotContainsString('FAKE_FIXTURE_ONLY', $output);
    }
}
