<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Pipeline;

use ContextGate\Pipeline\CanaryCheck;
use ContextGate\Pipeline\Normalizer;
use ContextGate\Pipeline\Payload;
use ContextGate\Tests\Fixtures\CanaryValues;
use PHPUnit\Framework\TestCase;

/** User Story 5, FR-017: a canary in the normalized output blocks (research R8). */
class CanaryCheckTest extends TestCase
{
    public function test_a_canary_inside_normalized_output_is_detected(): void
    {
        $payload = new Payload([['note' => 'write to ' . CanaryValues::EMAIL]], ['note']);

        $result = $this->check([CanaryValues::EMAIL], $payload);

        $this->assertSame([CanaryCheck::FLAG], $result->flags);
    }

    public function test_a_canary_split_by_a_zero_width_space_is_detected_after_normalization(): void
    {
        $split = 'canary-7f3a@ex' . "\u{200B}" . 'ample.invalid';
        $payload = new Payload([['note' => $split]], ['note']);

        $normalized = (new Normalizer(['note']))->handle($payload, static fn (Payload $next): Payload => $next);
        $result = $this->check([CanaryValues::EMAIL], $normalized);

        $this->assertSame([CanaryCheck::FLAG], $result->flags);
    }

    public function test_a_canary_with_a_slash_is_detected_when_it_is_serialized(): void
    {
        $payload = new Payload([['path' => 'tokens/CANARY_TOKEN_0c9e1a/x']], ['path']);

        $result = $this->check(['tokens/CANARY_TOKEN_0c9e1a'], $payload);

        $this->assertSame([CanaryCheck::FLAG], $result->flags);
    }

    public function test_a_canary_with_a_double_quote_is_detected_in_its_escaped_form(): void
    {
        $canary = 'say "CANARY_QUOTE_9d1" now';
        $payload = new Payload([['note' => 'leak: ' . $canary]], ['note']);

        $result = $this->check([$canary], $payload);

        $this->assertSame([CanaryCheck::FLAG], $result->flags);
    }

    public function test_an_email_canary_is_detected_in_upper_case(): void
    {
        $payload = new Payload([['note' => 'write to CANARY-7F3A@EXAMPLE.INVALID']], ['note']);

        $result = $this->check([CanaryValues::EMAIL], $payload);

        $this->assertSame([CanaryCheck::FLAG], $result->flags);
    }

    public function test_a_token_canary_is_detected_in_any_letter_case(): void
    {
        $payload = new Payload([['note' => 'token ' . strtolower(CanaryValues::TOKEN)]], ['note']);

        $result = $this->check([CanaryValues::TOKEN], $payload);

        $this->assertSame([CanaryCheck::FLAG], $result->flags);
    }

    public function test_a_canary_in_decomposed_form_is_detected_in_composed_data(): void
    {
        $payload = new Payload([['note' => "Caf\u{00E9} token"]], ['note']);

        $result = $this->check(["Cafe\u{0301} token"], $payload);

        $this->assertSame([CanaryCheck::FLAG], $result->flags);
    }

    public function test_with_no_canary_configured_nothing_blocks(): void
    {
        $payload = new Payload([['note' => CanaryValues::EMAIL]], ['note']);

        $result = $this->check([], $payload);

        $this->assertSame([], $result->flags);
    }

    public function test_an_empty_canary_value_is_ignored_and_does_not_block_everything(): void
    {
        $payload = new Payload([['note' => 'plain text']], ['note']);

        $result = $this->check([''], $payload);

        $this->assertSame([], $result->flags);
    }

    public function test_a_clean_response_passes_to_the_next_stage(): void
    {
        $payload = new Payload([['note' => 'plain text']], ['note']);

        $result = $this->check([CanaryValues::TOKEN], $payload);

        $this->assertSame([], $result->flags);
        $this->assertSame([['note' => 'plain text']], $result->rows);
    }

    /** @param list<string> $canaries */
    private function check(array $canaries, Payload $payload): Payload
    {
        $nextCalled = false;

        $result = (new CanaryCheck($canaries))->handle(
            $payload,
            function (Payload $next) use (&$nextCalled): Payload {
                $nextCalled = true;

                return $next;
            },
        );

        if ($result->flags !== []) {
            $this->assertFalse($nextCalled, 'a blocked payload must not reach the next stage');
        } else {
            $this->assertTrue($nextCalled, 'a clean payload must reach the next stage');
        }

        return $result;
    }
}
