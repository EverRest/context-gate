<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Contract\ContextContract;
use ContextGate\Source\RunbookTool;
use LogicException;

final class ToolRegistry
{
    /** @var array<string, array{tool: Tool, enabled: bool, pinned: bool}> */
    private array $tools = [];

    /** @param array<string, array<string, mixed>> $config */
    public function __construct(array $config)
    {
        foreach ($config as $key => $entry) {
            $class = $entry['class'] ?? null;

            if (! is_string($class) || ! is_a($class, Tool::class, true)) {
                throw new LogicException("Tool entry [{$key}] needs a class that extends ".Tool::class.'.');
            }

            $tool = self::instantiate((string) $key, $class);

            if (preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $tool->name()) !== 1) {
                throw new LogicException("Tool name [{$tool->name()}] is invalid: use 1 to 128 characters from A-Z a-z 0-9 _ . -.");
            }

            if (isset($this->tools[$tool->name()])) {
                throw new LogicException("Tool name [{$tool->name()}] is configured more than once.");
            }

            $this->tools[$tool->name()] = [
                'tool' => $tool,
                'enabled' => ($entry['enabled'] ?? false) === true,
                'pinned' => ($entry['fingerprint'] ?? null) === self::pinOf($tool),
            ];
        }
    }

    /** The tool object of one configured entry. A runbook tool takes its name from its key; any other tool comes from the container. */
    public static function instantiate(string $key, string $class): Tool
    {
        return is_a($class, RunbookTool::class, true) ? new RunbookTool($key) : app($class);
    }

    /** What the approved fingerprint must equal: the runbook fingerprint for a runbook tool (spec 018 FR-020), else the tool fingerprint. */
    public static function pinOf(Tool $tool): string
    {
        return $tool instanceof RunbookTool ? $tool->fingerprint() : Fingerprint::of($tool);
    }

    /**
     * The contract class of one tool entry, or null when it names none or names a class that is not a contract.
     *
     * @return class-string<ContextContract>|null
     */
    public static function contractClassOf(mixed $entry): ?string
    {
        $class = is_array($entry) ? ($entry['contract'] ?? null) : null;

        return is_string($class) && is_a($class, ContextContract::class, true) ? $class : null;
    }

    /** @return class-string<ContextContract>|null */
    public static function contractClass(string $tool): ?string
    {
        /** @var array<string, mixed> $tools */
        $tools = config('context-gate.tools', []);

        return self::contractClassOf($tools[$tool] ?? null);
    }

    public static function contractFor(string $tool): ?ContextContract
    {
        $class = self::contractClass($tool);

        return $class === null ? null : app($class);
    }

    public function find(string $name): ?Tool
    {
        return $this->tools[$name]['tool'] ?? null;
    }

    /** @return list<Tool> */
    public function visibleTo(Caller $caller): array
    {
        $visible = array_filter($this->tools, fn (array $entry, string $name): bool => $this->isVisible($name, $caller), ARRAY_FILTER_USE_BOTH);

        return array_values(array_map(static fn (array $entry): Tool => $entry['tool'], $visible));
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
