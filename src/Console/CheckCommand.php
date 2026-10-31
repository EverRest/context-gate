<?php

declare(strict_types=1);

namespace ContextGate\Console;

use ContextGate\Classification\Catalog;
use ContextGate\Config\CapsValidator;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\ContractValidator;
use Illuminate\Console\Command;
use InvalidArgumentException;

/** FR-025 / research R13: loads every contract, the catalog, the limits and the canaries, and fails on any rule. */
class CheckCommand extends Command
{
    protected $signature = 'gate:check';

    protected $description = 'Check the contracts, the classification catalog, the limits and the canary values';

    public function handle(): int
    {
        $problems = $this->problems();

        foreach ($problems as $problem) {
            $this->line("FAIL {$problem}");
        }

        if ($problems === []) {
            $this->line('gate:check passed');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    /** @return list<string> one line per problem, each naming the key or contract and the rule */
    private function problems(): array
    {
        /** @var array<string, array<string, mixed>> $map */
        $map = config('context-gate.classification', []);

        try {
            $catalog = new Catalog($map);
        } catch (InvalidArgumentException $e) {
            return ['classification: '.$e->getMessage()];
        }

        $problems = [];

        foreach ((array) config('context-gate.tools', []) as $name => $entry) {
            $contract = is_array($entry) ? ($entry['contract'] ?? null) : null;

            if ($contract === null) {
                continue;
            }

            if (! is_string($contract) || ! is_a($contract, ContextContract::class, true)) {
                $problems[] = "tools.{$name}.contract: is not a contract class";

                continue;
            }

            try {
                (new ContractValidator())->validate(new $contract(), $catalog);
            } catch (InvalidArgumentException $e) {
                $problems[] = "tools.{$name}.contract: {$e->getMessage()}";
            }
        }

        // Only integer limits are compared; CapsValidator knows which keys have a cap.
        $limits = [];

        foreach ((array) config('context-gate.firewall.limits', []) as $key => $value) {
            if (is_int($value)) {
                $limits["firewall.limits.{$key}"] = $value;
            }
        }

        foreach (CapsValidator::violations($limits) as $key) {
            $problems[] = "{$key}: is above its code cap";
        }

        foreach ((array) config('context-gate.canaries.values', []) as $index => $canary) {
            if (! is_string($canary) || $canary === '') {
                $problems[] = "canaries.values[{$index}]: must be a non-empty string";
            }
        }

        return $problems;
    }
}
