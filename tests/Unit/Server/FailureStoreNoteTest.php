<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Server\Failure;
use ContextGate\Support\StoreUnavailable;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Log;

class FailureStoreNoteTest extends TestCase
{
    public function test_a_store_failure_is_noted_with_the_class_and_place_of_the_original(): void
    {
        $lines = [];
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $previous = extension_loaded('redis')
            ? new \RedisException('tcp://10.0.0.1 password=FAKE_FIXTURE_ONLY')
            : new \RuntimeException('tcp://10.0.0.1 password=FAKE_FIXTURE_ONLY');

        Failure::note(new StoreUnavailable($previous), 'r_x');

        $note = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('r_x', $note['reference']);
        $this->assertSame($previous::class, $note['exception']);
        $this->assertSame(basename(__FILE__), $note['file']);
        $this->assertSame($previous->getLine(), $note['line']);
        $this->assertStringNotContainsString('10.0.0.1', $lines[0]);
        $this->assertStringNotContainsString('FAKE_FIXTURE_ONLY', $lines[0]);
    }
}
