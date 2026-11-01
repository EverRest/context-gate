<?php

declare(strict_types=1);

namespace ContextGate\Console;

use ContextGate\Server\Simulator;
use Illuminate\Console\Command;

/**
 * Prints the decision a call by a configured caller would get, and its reason code. The handler
 * is not called, the per-minute budget is not used, and the receipt is marked simulated.
 *
 * @internal May change without notice.
 */
class SimulateCommand extends Command
{
    protected $signature = 'gate:simulate {tool : The tool to simulate} {caller : A configured caller id} {--args= : The arguments as a JSON object}';

    protected $description = 'Print the decision and reason a caller would get for a tool call; does not call the tool';

    public function handle(): int
    {
        // Same rule as gate:preview: the stdio server does not run in production.
        if (app()->environment('production')) {
            $this->error('gate:simulate does not run in production; use authenticated HTTP there.');

            return self::FAILURE;
        }

        $caller = $this->argument('caller');

        if (! is_string($caller) || ! array_key_exists($caller, (array) config('context-gate.callers', []))) {
            $this->error('caller is not a configured caller.');

            return self::FAILURE;
        }

        $raw = $this->option('args');
        $arguments = json_decode(is_string($raw) ? $raw : '{}', true);

        if (! is_array($arguments)) {
            $this->error('--args must be a JSON object.');

            return self::FAILURE;
        }

        $tool = $this->argument('tool');
        $reason = Simulator::decide($caller, is_string($tool) ? $tool : '', $arguments);

        $this->line('Decision: '.($reason?->decision() ?? 'allow'));
        $this->line('Reason: '.($reason === null ? 'none' : $reason->value));

        return self::SUCCESS;
    }
}
