<?php

declare(strict_types=1);

namespace ContextGate\Settings;

/**
 * spec 007 R8: the configuration in force, validated once when the instance is built. Calls read this result; an
 * invalid document refuses them with config.invalid, and nothing is silently ignored.
 *
 * @internal May change without notice.
 */
final class ActiveSettings
{
    /** @var list<array{key: string, message: string}> */
    private readonly array $violations;

    /**
     * @param array<string, mixed> $document the settings document in config
     * @param list<array{key: string, message: string}> $overlay violations of the stored versions (ConfigOverlay)
     */
    public function __construct(array $document, string $env, array $overlay = [])
    {
        $this->violations = [...SettingsValidator::violations($document, $env), ...$overlay];
    }

    public function valid(): bool
    {
        return $this->violations === [];
    }

    /** @return list<array{key: string, message: string}> */
    public function violations(): array
    {
        return $this->violations;
    }
}
