<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Privacy;

use ContextGate\Pipeline\Payload;
use ContextGate\Pipeline\Redact;
use ContextGate\Privacy\Redactor;
use ContextGate\Tests\Fixtures\SeededPii;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** User Story 6, FR-014: regular-expression redaction per text field (research R7). */
class RedactorTest extends TestCase
{
    public function test_seeded_values_are_replaced_by_placeholders(): void
    {
        $seeded = [
            '[EMAIL]' => SeededPii::EMAIL,
            '[PHONE]' => SeededPii::PHONE,
            '[IBAN]' => SeededPii::IBAN,
            '[CARD]' => SeededPii::CARD,
            '[TOKEN]' => SeededPii::BEARER_TOKEN,
        ];

        foreach ($seeded as $placeholder => $value) {
            $result = (new Redactor())->redact("before {$value} after");

            $this->assertSame("before {$placeholder} after", $result, "placeholder for {$placeholder}");
        }
    }

    public function test_a_spaced_card_number_is_replaced(): void
    {
        $result = (new Redactor())->redact('card 4539 1488 0343 6467 end');

        $this->assertSame('card [CARD] end', $result);
    }

    public function test_a_luhn_invalid_sixteen_digit_number_is_kept(): void
    {
        $result = (new Redactor())->redact('order 4539148803436468 end');

        $this->assertSame('order 4539148803436468 end', $result);
    }

    public function test_an_exception_on_one_field_drops_only_that_field(): void
    {
        $redactor = new class () extends Redactor {
            public function redact(string $text): string
            {
                if (str_contains($text, 'boom')) {
                    throw new RuntimeException('redactor failed');
                }

                return parent::redact($text);
            }
        };
        $payload = new Payload(
            [['note' => 'boom', 'title' => SeededPii::EMAIL]],
            ['note', 'title'],
        );

        $result = (new Redact($redactor, ['note', 'title']))->handle($payload, static fn (Payload $next): Payload => $next);

        $this->assertSame([['title' => '[EMAIL]']], $result->rows);
        $this->assertSame(['title'], $result->fields);
        $this->assertSame([['field' => 'note', 'reason' => 'redaction.failed']], $result->dropped);
    }

    public function test_the_stage_passes_the_payload_to_next(): void
    {
        $called = false;
        $next = static function (Payload $payload) use (&$called): Payload {
            $called = true;

            return $payload;
        };

        (new Redact(new Redactor(), ['note']))->handle(new Payload([['note' => 'hi']], ['note']), $next);

        $this->assertTrue($called);
    }
}
