<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Classification\Catalog;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\ContractValidator;

/** The load rules of a contract, checked on every call but computed once per contract class and classification map. */
final class ContractGuard
{
    /** @var array<string, string|null> */
    private static array $seen = [];

    /** The rule the contract breaks under the configured classification, or null when it is valid. */
    public static function violation(ContextContract $contract): ?string
    {
        /** @var array<string, array<string, mixed>> $map */
        $map = config('context-gate.classification', []);
        $key = $contract::class.'|'.md5(serialize($map));

        if (! array_key_exists($key, self::$seen)) {
            self::$seen[$key] = (new ContractValidator())->violation($contract, new Catalog($map));
        }

        return self::$seen[$key];
    }
}
