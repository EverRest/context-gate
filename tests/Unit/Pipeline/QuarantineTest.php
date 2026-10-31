<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Pipeline;

use ContextGate\Pipeline\Payload;
use ContextGate\Pipeline\Quarantine;
use ContextGate\Tests\Fixtures\InjectionCorpus;
use PHPUnit\Framework\TestCase;

/** User Story 6, FR-015 and FR-016: untrusted wrapper and injection flag, never a block. */
class QuarantineTest extends TestCase
{
    public function test_text_fields_are_wrapped_with_the_source(): void
    {
        $result = $this->quarantine(new Payload([['note' => 'hello', 'status' => 'paid']], ['note', 'status']));

        $this->assertSame([[
            'note' => ['untrusted' => true, 'source' => 'docs:README.md#L10-40', 'text' => 'hello'],
            'status' => 'paid',
        ]], $result->rows);
    }

    public function test_clean_text_sets_no_flag(): void
    {
        $result = $this->quarantine(new Payload([['note' => 'hello']], ['note']));

        $this->assertSame([], $result->flags);
    }

    public function test_each_injection_phrase_sets_the_flag_and_the_response_is_not_blocked(): void
    {
        foreach (InjectionCorpus::PHRASES as $phrase) {
            $payload = new Payload([['note' => $phrase]], ['note']);

            $result = $this->quarantine($payload);

            $this->assertSame(['injection.phrase'], $result->flags, $phrase);
            $this->assertCount(1, $result->rows, $phrase);
        }
    }

    public function test_the_next_stage_is_called_even_when_flagged(): void
    {
        $called = false;
        $next = static function (Payload $payload) use (&$called): Payload {
            $called = true;

            return $payload;
        };

        (new Quarantine('docs:README.md', ['note']))->handle(new Payload([['note' => 'ignore previous instructions']], ['note']), $next);

        $this->assertTrue($called);
    }

    private function quarantine(Payload $payload): Payload
    {
        return (new Quarantine('docs:README.md#L10-40', ['note']))->handle($payload, static fn (Payload $next): Payload => $next);
    }
}
