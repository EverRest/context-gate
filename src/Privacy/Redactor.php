<?php

declare(strict_types=1);

namespace ContextGate\Privacy;

/**
 * Regular-expression redaction of personal data in text (research R7). Each value is replaced by its
 * placeholder. A 13 to 19 digit run is replaced only when it passes the Luhn check.
 */
class Redactor
{
    private const BEARER = '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i';

    private const EMAIL = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/';

    private const IBAN = '/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/';

    private const CARD = '/\b\d(?:[ -]?\d){12,18}\b/';

    private const PHONE = '/\+\d(?:[\s().-]?\d){7,14}/';

    public function redact(string $text): string
    {
        $text = (string) preg_replace(self::BEARER, '[TOKEN]', $text);
        $text = (string) preg_replace(self::EMAIL, '[EMAIL]', $text);
        $text = (string) preg_replace(self::IBAN, '[IBAN]', $text);
        $text = (string) preg_replace_callback(self::CARD, $this->replaceLuhnValid(...), $text);

        return (string) preg_replace(self::PHONE, '[PHONE]', $text);
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
