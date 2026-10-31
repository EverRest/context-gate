<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Pipeline;

use Closure;
use ContextGate\Pipeline\Payload;
use ContextGate\Pipeline\Stage;
use PHPUnit\Framework\TestCase;

class StageTest extends TestCase
{
    public function test_a_stage_receives_the_payload_and_can_pass_it_to_next(): void
    {
        $stage = new class () implements Stage {
            public function handle(Payload $payload, Closure $next): Payload
            {
                $payload->flags[] = 'stage-ran';

                return $next($payload);
            }
        };

        $result = $stage->handle(new Payload([]), static fn (Payload $payload): Payload => $payload);

        $this->assertSame(['stage-ran'], $result->flags);
    }

    public function test_a_stage_can_short_circuit_without_calling_next(): void
    {
        $stage = new class () implements Stage {
            public function handle(Payload $payload, Closure $next): Payload
            {
                $payload->rows = [];

                return $payload;
            }
        };
        $nextCalled = false;

        $result = $stage->handle(
            new Payload([['id' => 1]]),
            function (Payload $payload) use (&$nextCalled): Payload {
                $nextCalled = true;

                return $payload;
            },
        );

        $this->assertFalse($nextCalled);
        $this->assertSame([], $result->rows);
    }
}
