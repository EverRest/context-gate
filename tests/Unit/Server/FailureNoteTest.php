<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Server\Failure;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FailureNoteTest extends TestCase
{
    public function test_an_anonymous_exception_class_leaks_no_path_or_nul(): void
    {
        $lines = [];
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        Failure::note(new class ('x') extends RuntimeException {
        }, 'ref');

        $this->assertCount(1, $lines);
        $this->assertStringNotContainsString('/', $lines[0]);
        $this->assertStringNotContainsString('\u0000', $lines[0]);
        $this->assertStringContainsString('anonymous', $lines[0]);
    }
}
