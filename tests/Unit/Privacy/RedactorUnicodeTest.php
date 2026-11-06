<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Privacy;

use ContextGate\Privacy\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Personal data outside ASCII and in its printed form: an address with accented letters must be redacted whole, not
 * cut at the first non-ASCII letter with the rest of the name left in the text.
 */
class RedactorUnicodeTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function redacted(): iterable
    {
        yield 'accented local part' => ['write to jürgen.müller@gmail.com today', 'write to [EMAIL] today'];
        yield 'accented domain' => ['write to anna@sport-österreich.at today', 'write to [EMAIL] today'];
        yield 'cyrillic address' => ['пишите на иван@пример.рф сегодня', 'пишите на [EMAIL] сегодня'];
        yield 'greek local part' => ['email σοφία@example.gr now', 'email [EMAIL] now'];
        yield 'spaced iban' => ['iban DE89 3704 0044 0532 0130 00 thanks', 'iban [IBAN] thanks'];
        yield 'lowercase iban' => ['iban de89370400440532013000 thanks', 'iban [IBAN] thanks'];
        yield 'spaced lowercase iban' => ['iban gb29 nwbk 6016 1331 9268 19 thanks', 'iban [IBAN] thanks'];
    }

    /** @return iterable<string, array{string}> */
    public static function kept(): iterable
    {
        yield 'names without an address' => ['Jürgen Müller and Zoë Ångström'];
        yield 'an at sign in prose' => ['meet @ the stadium, gate B'];
        yield 'a version and a date' => ['release 2026.11 on 05.11.2026'];
        yield 'words after a spaced group' => ['room AB12 CDEF block'];
    }

    #[DataProvider('redacted')]
    public function test_the_value_is_redacted_whole(string $text, string $expected): void
    {
        $this->assertSame($expected, (new Redactor())->redact($text));
    }

    #[DataProvider('kept')]
    public function test_ordinary_text_is_kept(string $text): void
    {
        $this->assertSame($text, (new Redactor())->redact($text));
    }
}
