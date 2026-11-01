<?php

declare(strict_types=1);

namespace ContextGate\Console;

use ContextGate\Classification\Catalog;
use ContextGate\Contract\ContextContract;
use ContextGate\Readiness\CheckRegistry;
use ContextGate\Readiness\CheckResult;
use ContextGate\Readiness\CheckStatus;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Server\ToolRegistry;
use ContextGate\Settings\EffectiveSettings;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * spec 006 US2 (FR-005 to FR-014): runs every readiness check, then the spec 002 rules that are not yet a check
 * (the ceiling, the source settings and the source tables). Exit 0 only when no line is FAIL.
 *
 * @internal May change without notice.
 */
class CheckCommand extends Command
{
    /** Source tool => the pseudo-table its rows are classified under (spec 003, config/context-gate.php). */
    private const SOURCE_TABLES = [
        'routes.list' => 'source_routes',
        'config.keys' => 'source_config_keys',
        'schema.describe' => 'source_schema_items',
        'docs.read' => 'source_docs',
        'openapi.endpoints' => 'source_openapi_endpoints',
    ];

    protected $signature = 'gate:check {--json : Print the results as JSON}';

    protected $description = 'Check the installation: configuration, catalog, contracts, canaries, extensions, origins, tokens and transport';

    public function handle(): int
    {
        /** @var array<string, array<string, mixed>> $map */
        $map = (array) config('context-gate.classification', []);
        $results = [];

        try {
            $catalog = new Catalog($map);
        } catch (InvalidArgumentException $e) {
            $catalog = new Catalog([]);
            $results[] = CheckResult::fail('classification', $e->getMessage(), 'fix the class named above in config/context-gate.php');
        }

        $ctx = new ReadinessContext((array) config('context-gate', []), $catalog, $this->contracts());

        $results = [
            ...$results,
            ...$this->ceilingResults(),
            ...$this->sourceResults($map),
            ...$this->registryResults($ctx),
        ];

        return $this->report($results);
    }

    /** @return list<CheckResult> */
    private function registryResults(ReadinessContext $ctx): array
    {
        $results = [];

        foreach (CheckRegistry::all() as $class) {
            $results[] = (new $class())->run($ctx);
        }

        return $results;
    }

    /** @return list<ContextContract> the contracts of every tool entry that names a contract class */
    private function contracts(): array
    {
        $contracts = [];

        foreach ((array) config('context-gate.tools', []) as $entry) {
            $contract = ToolRegistry::contractClassOf($entry);

            if ($contract !== null) {
                $contracts[] = app($contract);
            }
        }

        return $contracts;
    }

    /** @return list<CheckResult> */
    private function ceilingResults(): array
    {
        if (EffectiveSettings::ceiling() === null) {
            return [CheckResult::fail('firewall.ceiling', 'must be public or internal', 'set firewall.ceiling in config/context-gate.php to public or internal')];
        }

        return [];
    }

    /**
     * One result per bad source setting. Paths, models and env names are checked always; the classification table
     * of a source is required only while its tool is enabled (spec 003, research R3 and R5).
     *
     * @param array<string, array<string, mixed>> $map the classification map
     * @return list<CheckResult>
     */
    private function sourceResults(array $map): array
    {
        $results = [];

        foreach (['docs', 'openapi'] as $source) {
            foreach ((array) config("context-gate.sources.{$source}.paths", []) as $index => $path) {
                $subject = "sources.{$source}.paths[{$index}]";
                $fix = 'use a relative folder path such as docs/ in config/context-gate.php';

                if (! is_string($path) || $path === '' || str_starts_with($path, '/') || preg_match('/[*?\[\]{}]/', $path) === 1
                    || in_array('..', explode('/', $path), true)) {
                    $results[] = CheckResult::fail($subject, 'must be a relative path inside the application, without .. or wildcards', $fix);
                } elseif (! str_ends_with($path, '/') && is_dir(base_path($path))) {
                    $results[] = CheckResult::fail($subject, 'a folder needs a trailing slash', $fix);
                }
            }
        }

        if (EffectiveSettings::examples() === null) {
            $results[] = CheckResult::fail('sources.openapi.examples', 'must be drop or redacted', 'set sources.openapi.examples to drop (the default) or redacted in config/context-gate.php');
        }

        foreach ((array) config('context-gate.sources.eloquent.models', []) as $index => $model) {
            if (! is_string($model) || ! is_a($model, Model::class, true)) {
                $results[] = CheckResult::fail("sources.eloquent.models[{$index}]", 'is not an Eloquent model class', 'list Eloquent model class names in config/context-gate.php');
            }
        }

        foreach ((array) config('context-gate.sources.config.env_map', []) as $key => $variable) {
            if (! is_string($variable)) {
                $results[] = CheckResult::fail("sources.config.env_map.{$key}", 'must be an environment variable name', 'map the config key to an environment variable name');
            }
        }

        $tools = (array) config('context-gate.tools', []);

        foreach (self::SOURCE_TABLES as $name => $table) {
            $entry = $tools[$name] ?? null;

            if (is_array($entry) && ($entry['enabled'] ?? false) === true && ! isset($map[$table])) {
                $results[] = CheckResult::fail("classification.{$table}", "source table of {$name} is missing", "add the {$table} entry to classification in config/context-gate.php");
            }
        }

        return $results;
    }

    /** @param list<CheckResult> $results */
    private function report(array $results): int
    {
        $failed = array_filter($results, static fn (CheckResult $result): bool => $result->status === CheckStatus::Fail) !== [];

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(
                static fn (CheckResult $result): array => [
                    'status' => $result->status->value,
                    'subject' => $result->subject,
                    'message' => $result->message,
                    'fix' => $result->fix,
                ],
                $results,
            ), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            foreach ($results as $result) {
                $this->line(sprintf('%s %s: %s', strtoupper($result->status->value), $result->subject, $result->message));

                if ($result->fix !== '') {
                    $this->line("fix: {$result->fix}");
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
