<?php

declare(strict_types=1);

namespace ContextGate\Console;

use ContextGate\Classification\DraftWriter;
use ContextGate\Source\RoutesSource;
use ContextGate\Source\SourceRequest;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * spec 004 US2 (FR-012, FR-018): reads the routes and the configured models and prints a compact report with a
 * suggested class per column, from the same name rule gate:classify uses. It enables no tool and changes no
 * configuration; only --json writes storage/context-gate/scan.json. Names and classes only, never a row value.
 */
class ScanCommand extends Command
{
    protected $signature = 'gate:scan {--json : Write storage/context-gate/scan.json}';

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
            $table = (new $model())->getTable();
            $columns = [];

            if (Schema::hasTable($table)) {
                foreach (Schema::getColumns($table) as $column) {
                    [$class, $marker] = $writer->classify((string) $column['name']);

                    $columns[] = [
                        'name' => (string) $column['name'],
                        'suggested' => $marker === 'suggested' ? $class : null,
                    ];
                }
            }

            $report[] = ['model' => $model, 'table' => $table, 'columns' => $columns];
        }

        $this->line('Routes: '.count($routes));
        $this->line('Models: '.count($report));

        foreach ($report as $entry) {
            $this->line("  {$entry['model']} ({$entry['table']})");

            if ($entry['columns'] === []) {
                $this->line('    no table or no columns');
            }

            foreach ($entry['columns'] as $column) {
                $this->line('    '.$column['name'].'  '.($column['suggested'] === null ? 'no suggestion' : $column['suggested'].' (suggested)'));
            }
        }

        if ($this->option('json')) {
            $target = base_path('storage/context-gate/scan.json');

            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }

            file_put_contents($target, json_encode(['routes' => $routes, 'models' => $report], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");

            $this->info('Wrote storage/context-gate/scan.json.');
        }

        return self::SUCCESS;
    }
}
