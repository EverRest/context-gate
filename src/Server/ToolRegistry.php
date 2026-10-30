<?php

declare(strict_types=1);

namespace ContextGate\Server;

use LogicException;

final class ToolRegistry
{
    /** @var array<string, array{tool: Tool, enabled: bool, pinned: bool}> */
    private array $tools = [];

    /** @param array<string, array<string, mixed>> $config */
    public function __construct(array $config)
    {
        foreach ($config as $entry) {
            $class = $entry['class'] ?? null;

            if (! is_string($class) || ! is_a($class, Tool::class, true)) {
                throw new LogicException('Each configured tool needs a class that extends '.Tool::class.'.');
            }

            $tool = new $class();

            if (isset($this->tools[$tool->name()])) {
                throw new LogicException("Tool name [{$tool->name()}] is configured more than once.");
            }

            $this->tools[$tool->name()] = [
                'tool' => $tool,
                'enabled' => ($entry['enabled'] ?? false) === true,
                'pinned' => ($entry['fingerprint'] ?? null) === Fingerprint::of($tool),
            ];
        }
    }

    public function find(string $name): ?Tool
    {
        return $this->tools[$name]['tool'] ?? null;
    }

    public function isVisible(string $name, Caller $caller): bool
    {
        return $this->isApproved($name) && in_array($name, $caller->tools, true);
    }

    /** True when the tool is enabled but its description no longer matches the approved fingerprint (R9). */
    public function isPinMismatch(string $name): bool
    {
        $entry = $this->tools[$name] ?? null;

        return $entry !== null && $entry['enabled'] && ! $entry['pinned'];
    }

    private function isApproved(string $name): bool
    {
        $entry = $this->tools[$name] ?? null;

        return $entry !== null && $entry['enabled'] && $entry['pinned'];
    }
}
