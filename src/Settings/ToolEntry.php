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
    /** One configured tool entry, as the settings hold it (the validated shape). */
    /** @param array<string, mixed> $entry */
    public static function fromConfig(string $name, array $entry): self
    {
        return new self(
            $name,
            (string) ($entry['class'] ?? ''),
            ($entry['enabled'] ?? false) === true,
            is_string($entry['gate'] ?? null) ? $entry['gate'] : null,
            is_string($entry['fingerprint'] ?? null) ? $entry['fingerprint'] : null,
            is_string($entry['contract'] ?? null) ? $entry['contract'] : null,
            is_string($entry['risk'] ?? null) ? $entry['risk'] : null,
        );
    }

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
