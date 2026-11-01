<?php

declare(strict_types=1);

namespace ContextGate\Readiness;

use ContextGate\Readiness\Checks\AbilitySetCheck;
use ContextGate\Readiness\Checks\CanaryCheck;
use ContextGate\Readiness\Checks\CapsCheck;
use ContextGate\Readiness\Checks\CatalogCheck;
use ContextGate\Readiness\Checks\ContractCheck;
use ContextGate\Readiness\Checks\ExtensionsCheck;
use ContextGate\Readiness\Checks\OriginCheck;
use ContextGate\Readiness\Checks\SettingsCheck;
use ContextGate\Readiness\Checks\TokenCallersCheck;
use ContextGate\Readiness\Checks\TokenTableCheck;
use ContextGate\Readiness\Checks\TransportCheck;
use ContextGate\Readiness\Checks\UrlAllowlistCheck;

/** @internal May change without notice. */
final class CheckRegistry
{
    /** @return list<class-string<Check>> */
    public static function all(): array
    {
        return [
            CapsCheck::class,
            CatalogCheck::class,
            ContractCheck::class,
            CanaryCheck::class,
            ExtensionsCheck::class,
            OriginCheck::class,
            TokenCallersCheck::class,
            TokenTableCheck::class,
            TransportCheck::class,
            UrlAllowlistCheck::class,
            AbilitySetCheck::class,
            SettingsCheck::class,
        ];
    }
}
