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
    /** Source tool => the pseudo-table its rows are classified under (spec 003, config/context-gate.php). */
    private const SOURCE_TABLES = [
        'routes.list' => 'source_routes',
        'config.keys' => 'source_config_keys',
        'schema.describe' => 'source_schema_items',
        'docs.read' => 'source_docs',
    ];

    protected $signature = 'gate:check';

    protected $description = 'Check the contracts, the classification catalog, the limits and the canary values';

    public function handle(): int
    {
        if ($this->canariesUnset()) {
            $this->line('WARN canaries: no canary values set; canary protection is off');
        }

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

        if (! $this->canariesUnset()) {
            foreach ((array) config('context-gate.canaries.values', []) as $index => $canary) {
                if ($canary === null) {
                    continue;
                }

                if (! is_string($canary) || $canary === '') {
                    $problems[] = "canaries.values[{$index}]: must be a non-empty string";
                }
            }
        }

        return [...$problems, ...$this->sourceProblems($map)];
    }

    /**
     * Checks the source settings of each enabled source tool only (spec 003, research R5 and R3).
     *
     * @param array<string, array<string, mixed>> $map the classification map
     * @return list<string>
     */
    private function sourceProblems(array $map): array
    {
        $tools = (array) config('context-gate.tools', []);
        $enabled = [];

        foreach (self::SOURCE_TABLES as $name => $table) {
            $entry = $tools[$name] ?? null;

            if (is_array($entry) && ($entry['enabled'] ?? false) === true) {
                $enabled[$name] = $table;
            }
        }

        $problems = [];

        if (isset($enabled['docs.read'])) {
            foreach ((array) config('context-gate.sources.docs.paths', []) as $index => $path) {
                if (! is_string($path) || $path === '' || str_starts_with($path, '/') || in_array('..', explode('/', $path), true)
                    || preg_match('/[*?\[\]{}]/', $path) === 1) {
                    $problems[] = "sources.docs.paths[{$index}]: must be a relative path with no '..' segment and no glob characters";
                }
            }
        }

        if (isset($enabled['schema.describe'])) {
            foreach ((array) config('context-gate.sources.eloquent.models', []) as $index => $model) {
                if (! is_string($model) || ! class_exists($model)) {
                    $problems[] = "sources.eloquent.models[{$index}]: class does not exist";
                }
            }
        }

        if (isset($enabled['config.keys'])) {
            foreach ((array) config('context-gate.sources.config.env_map', []) as $key => $variable) {
                if (! is_string($variable)) {
                    $problems[] = "sources.config.env_map.{$key}: must be a string";
                }
            }
        }

        foreach ($enabled as $name => $table) {
            if (! isset($map[$table])) {
                $problems[] = "classification.{$table}: source table of {$name} is missing";
            }
        }

        return $problems;
    }

    /** The unset env case: no values, or only nulls (an unset env()) and empty strings. A warning, not a failure. */
    private function canariesUnset(): bool
    {
        foreach ((array) config('context-gate.canaries.values', []) as $canary) {
            if ($canary !== '' && $canary !== null) {
                return false;
            }
        }

        return true;
    }
}
