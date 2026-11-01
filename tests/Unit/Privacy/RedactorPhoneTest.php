<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Privacy;

use ContextGate\Privacy\Redactor;
use PHPUnit\Framework\TestCase;

/** Local phone formats are redacted; dates, versions, amounts, ids and timestamps are kept. */
class RedactorPhoneTest extends TestCase
{
    public function test_local_formats_are_redacted(): void
    {
        foreach (['(555) 123-4567', '0501234567', '050 123 45 67', '555.123.4567', '+380 50 123 45 67'] as $phone) {
            $this->assertSame('call [PHONE] now', (new Redactor())->redact("call {$phone} now"), $phone);
        }
    }

    public function test_ordinary_numbers_are_kept(): void
    {
        foreach (['2026-11-01', 'v1.2.3', '1234567.89', '1234567', '12345678', '1700000000', '2026-11-01T10:20:30', 'a/0501234567'] as $kept) {
            $this->assertSame("x {$kept} y", (new Redactor())->redact("x {$kept} y"), $kept);
        }
    }

    public function test_a_valid_card_is_still_a_card_not_a_phone(): void
    {
        $this->assertSame('card [CARD] end', (new Redactor())->redact('card 4539 1488 0343 6467 end'));
    }
}
