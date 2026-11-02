<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Concurrency;

use ContextGate\Tests\Concurrency\ConcurrencyTestCase;
use PHPUnit\Framework\TestCase;

/** Review finding (spec 008): the Concurrency setup says why a run cannot go ahead; the Redis job must not skip past it. */
class SetupBlockerTest extends TestCase
{
    public function test_the_opt_in_is_required_to_run(): void
    {
        $this->assertStringContainsString('CONTEXT_GATE_CONCURRENCY=1', (string) ConcurrencyTestCase::setupBlocker(null, true, null));
        $this->assertNull(ConcurrencyTestCase::setupBlocker('1', true, null));
    }

    public function test_the_redis_store_needs_the_phpredis_extension(): void
    {
        $this->assertStringContainsString('phpredis', (string) ConcurrencyTestCase::setupBlocker('1', false, 'redis'));
        $this->assertNull(ConcurrencyTestCase::setupBlocker('1', true, 'redis'));
        $this->assertNull(ConcurrencyTestCase::setupBlocker('1', false, null), 'the file store needs no extension');
    }
}
