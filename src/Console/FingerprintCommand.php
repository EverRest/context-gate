<?php

declare(strict_types=1);

namespace ContextGate\Console;

use ContextGate\Server\Tool;
use ContextGate\Server\ToolRegistry;
use Illuminate\Console\Command;

/** @internal May change without notice. */
class FingerprintCommand extends Command
{
    protected $signature = 'gate:fingerprint {tool : Tool name as configured under context-gate.tools}';

    protected $description = 'Print the pin fingerprint (name, description, schema, annotations) to paste into context-gate.tools.<name>.fingerprint';

    public function handle(): int
    {
        $name = $this->argument('tool');
        $class = is_string($name) ? config('context-gate.tools', [])[$name]['class'] ?? null : null;

        if (! is_string($class) || ! is_a($class, Tool::class, true)) {
            $this->error('The tool is not configured with a class under context-gate.tools.');

            return self::FAILURE;
        }

        $this->line(ToolRegistry::pinOf(ToolRegistry::instantiate((string) $name, $class)));

        return self::SUCCESS;
    }
}
