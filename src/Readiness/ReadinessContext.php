<?php

declare(strict_types=1);

namespace ContextGate\Readiness;

use ContextGate\Classification\Catalog;
use ContextGate\Contract\ContextContract;

/** @internal May change without notice. */
final readonly class ReadinessContext
{
    /**
     * @param array<string, mixed> $config
     * @param list<ContextContract> $contracts
     */
    public function __construct(
        public array $config,
        public Catalog $catalog,
        public array $contracts,
    ) {
    }
}
