<?php

declare(strict_types=1);

namespace ContextGate\Privacy;

use ContextGate\Contracts\RedactsText;

/**
 * Regular-expression redaction of personal data in text. Each value is replaced by its
 * placeholder. A 13 to 19 digit run is replaced only when it passes the Luhn check.
 *
 * @internal Do not extend to customise: bind your own implementation of the interface instead.
 */
class Redactor implements RedactsText
{
    private const BEARER = '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i';

    /** Letters of any script: an ASCII-only class stopped at the first accented letter and left the rest of the name. */
    private const EMAIL = '/[\p{L}\p{M}\p{N}._%+-]+@[\p{L}\p{M}\p{N}.-]+\.[\p{L}\p{M}]{2,}/u';

    /** Either case; unbroken, or in the printed form of groups of four separated by single spaces. */
    private const IBAN = '/\b[A-Z]{2}\d{2}(?:[A-Z0-9]{11,30}|(?: [A-Z0-9]{4}){2,7}(?: [A-Z0-9]{1,4})?)\b/i';

    private const CARD = '/\b\d(?:[ -]?\d){12,18}\b/';

    /**
     * International (+ prefix) or local: 9 to 15 digits with optional spaces, dots, dashes, parentheses. A bare
     * digit run needs a leading 0 (so timestamps stay), a decimal amount like 1234567.89 is kept, see replacePhone.
     */
    private const PHONE = '/\+\d(?:[\s().-]?\d){7,14}|(?<![\w.\-\/])\(?\d(?:[\s().-]{0,2}\d){8,14}(?![\w\-\/]|\.\d)/';

    public function redact(string $text): string
    {
        $text = (string) preg_replace(self::BEARER, '[TOKEN]', $text);
        $text = (string) preg_replace(self::EMAIL, '[EMAIL]', $text);
        $text = (string) preg_replace(self::IBAN, '[IBAN]', $text);
        $text = (string) preg_replace_callback(self::CARD, $this->replaceLuhnValid(...), $text);

        return (string) preg_replace_callback(self::PHONE, $this->replacePhone(...), $text);
    }

    /** @param array<array-key, string> $match */
    private function replacePhone(array $match): string
    {
        $value = (string) $match[0];
        $keep = preg_match('/^\d+\.\d{1,2}$/', $value) === 1
            || (preg_match('/^[+(]|[\s().-]/', $value) !== 1 && $value[0] !== '0');

        return $keep ? $value : '[PHONE]';
    }

    /** @param array<array-key, string> $match */
    private function replaceLuhnValid(array $match): string
    {
        $value = (string) $match[0];

        return self::luhnValid((string) preg_replace('/\D/', '', $value)) ? '[CARD]' : $value;
    }

    private static function luhnValid(string $digits): bool
    {
        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];

            if ($double) {
                $n *= 2;

                if ($n > 9) {
                    $n -= 9;
                }
            }

            $sum += $n;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
