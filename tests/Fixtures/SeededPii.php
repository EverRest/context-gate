<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

/**
 * Personal data seeded into fixtures. Every value is fake and unique; none belongs to a real person,
 * account or card. The privacy suite asserts that none of them reaches a response or a receipt.
 */
final class SeededPii
{
    /** Fake email on the reserved .invalid domain. */
    public const EMAIL = 'pii-fixture-01@example.invalid';

    /** Fake E.164 phone number in the 555 range. */
    public const PHONE = '+15555550123';

    /** Fake IBAN (the standard documentation example, valid check digits). */
    public const IBAN = 'DE89370400440532013000';

    /** Fake card number that passes the Luhn check. */
    public const CARD = '4539148803436467';

    /** Fake bearer token. */
    public const BEARER_TOKEN = 'Bearer FAKE_FIXTURE_ONLY_8a2f6c1d9e4b7305';

    private function __construct()
    {
    }
}
