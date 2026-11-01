<?php

declare(strict_types=1);

namespace ContextGate\Console;

use ContextGate\Classification\DraftWriter;
use ContextGate\Source\RoutesSource;
use ContextGate\Source\SourceRequest;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * spec 004 US2 (FR-012, FR-018): reads the routes and the configured models and prints a compact report with a
 * suggested class per column, from the same name rule gate:classify uses. It enables no tool and changes no
 * configuration; --json prints the report as JSON and --output=<path> writes it to a file. Names and classes only, never a row value.
 *
 * @internal May change without notice.
 */
class ScanCommand extends Command
{
    protected $signature = 'gate:scan {--json : Print the report as JSON instead of text} {--output= : Also write the JSON report to this file}';

    protected $description = 'Propose a catalog draft from the routes and models; it enables nothing and changes no configuration';

    public function handle(DraftWriter $writer): int
    {
        $routes = (new RoutesSource())->read(new SourceRequest([]))->data['routes'];

        /** @var list<class-string<Model>> $models */
        $models = array_values(array_filter(
            (array) config('context-gate.sources.eloquent.models', []),
            static fn (mixed $model): bool => is_string($model) && is_a($model, Model::class, true),
        ));

        $report = [];

        foreach ($models as $model) {
            $instance = new $model();
            $table = $instance->getTable();
            $schema = $instance->getConnection()->getSchemaBuilder();
            $columns = [];

            if ($schema->hasTable($table)) {
                foreach ($schema->getColumns($table) as $column) {
                    [$class, $marker] = $writer->classify((string) $column['name']);

                    $columns[] = [
                        'name' => (string) $column['name'],
                        'suggested' => $marker === 'suggested' ? $class : null,
                    ];
                }
            }

            $report[] = ['model' => $model, 'table' => $table, 'columns' => $columns];
        }

        $json = json_encode(['routes' => $routes, 'models' => $report], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            if (! is_dir(dirname($output))) {
                mkdir(dirname($output), 0755, true);
            }

            file_put_contents($output, $json."\n");
        }

        if ($this->option('json')) {
            $this->line($json);

            return self::SUCCESS;
        }

        $this->line('Routes: '.count($routes));
        $this->line('Models: '.count($report).($report === [] ? ' (set sources.eloquent.models to scan models)' : ''));

        foreach ($report as $entry) {
            $this->line("  {$entry['model']} ({$entry['table']})");

            if ($entry['columns'] === []) {
                $this->line('    no table or no columns');
            }

            foreach ($entry['columns'] as $column) {
                $this->line('    '.$column['name'].'  '.($column['suggested'] === null ? 'no suggestion' : $column['suggested'].' (suggested)'));
            }
        }

        if (is_string($output) && $output !== '') {
            $this->info("Wrote {$output}.");
        }

        return self::SUCCESS;
    }
}
