<?php

declare(strict_types=1);

namespace ContextGate\Settings;

/**
 * One tool as the settings declare it: the registry key, the tool class, and the gate, fingerprint and contract it is bound to.
 *
 * @internal May change without notice.
 */
final readonly class ToolEntry
{
    public function __construct(
        public string $name,
        public string $class,
        public bool $enabled,
        public ?string $gate,
        public ?string $fingerprint,
        public ?string $contract,
        public ?string $risk = null,
    ) {
    }
}
