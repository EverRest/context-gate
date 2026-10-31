<?php

declare(strict_types=1);

namespace ContextGate\Console;

use ContextGate\Classification\Catalog;
use ContextGate\Classification\Ceiling;
use ContextGate\Config\CapsValidator;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\ContractValidator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
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
                if (is_array($entry) && ($entry['enabled'] ?? false) === true) {
                    $problems[] = "tools.{$name}: enabled tool has no contract";
                }

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

        $ceiling = config('context-gate.firewall.ceiling', Ceiling::Internal->value);

        if (! is_string($ceiling) || Ceiling::tryFrom($ceiling) === null) {
            $problems[] = 'firewall.ceiling: must be one of public, internal, personal, secret';
        }

        $problems = [...$problems, ...$this->sourceProblems($map)];

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

        return $problems;
    }

    /**
     * One line per bad source setting. Paths, models and env names are checked always; the classification table
     * of a source is required only while its tool is enabled (spec 003, research R3 and R5).
     *
     * @param array<string, array<string, mixed>> $map the classification map
     * @return list<string>
     */
    private function sourceProblems(array $map): array
    {
        $problems = [];

        foreach (['docs', 'openapi'] as $source) {
            foreach ((array) config("context-gate.sources.{$source}.paths", []) as $index => $path) {
                if (! is_string($path) || $path === '' || str_starts_with($path, '/') || preg_match('/[*?\[\]{}]/', $path) === 1
                    || in_array('..', explode('/', $path), true)) {
                    $problems[] = "sources.{$source}.paths[{$index}]: must be a relative path inside the application, without .. or wildcards";
                } elseif (! str_ends_with($path, '/') && is_dir(base_path($path))) {
                    $problems[] = "sources.{$source}.paths[{$index}]: a folder needs a trailing slash";
                }
            }
        }

        foreach ((array) config('context-gate.sources.eloquent.models', []) as $index => $model) {
            if (! is_string($model) || ! is_a($model, Model::class, true)) {
                $problems[] = "sources.eloquent.models[{$index}]: is not an Eloquent model class";
            }
        }

        foreach ((array) config('context-gate.sources.config.env_map', []) as $key => $variable) {
            if (! is_string($variable)) {
                $problems[] = "sources.config.env_map.{$key}: must be an environment variable name";
            }
        }

        $tools = (array) config('context-gate.tools', []);

        foreach (self::SOURCE_TABLES as $name => $table) {
            $entry = $tools[$name] ?? null;

            if (is_array($entry) && ($entry['enabled'] ?? false) === true && ! isset($map[$table])) {
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
