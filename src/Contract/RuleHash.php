<?php

declare(strict_types=1);

namespace ContextGate\Contract;

/**
 * Short fingerprint of a canonical rule definition, written into receipts (research R11).
 */
final class RuleHash
{
    public static function of(string $canonical): string
    {
        return substr(hash('sha256', $canonical), 0, 6);
    }
}
