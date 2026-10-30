<?php

declare(strict_types=1);

namespace ContextGate\Server;

use LogicException;

final class ToolRegistry
{
    /** @var array<string, array{tool: Tool, enabled: bool}> */
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

            $this->tools[$tool->name()] = ['tool' => $tool, 'enabled' => ($entry['enabled'] ?? false) === true];
        }
    }

    public function find(string $name): ?Tool
    {
        return $this->tools[$name]['tool'] ?? null;
    }

    public function isVisible(string $name, Caller $caller): bool
    {
        return ($this->tools[$name]['enabled'] ?? false) && in_array($name, $caller->tools, true);
    }
}
