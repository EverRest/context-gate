<?php

declare(strict_types=1);

namespace ContextGate\Console;

use ContextGate\Classification\Catalog;
use ContextGate\Classification\DraftWriter;
use ContextGate\Source\SchemaSource;
use ContextGate\Source\SourceRequest;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * spec 004 US3 (FR-013, FR-014): writes app/Gate/classification.php as a draft from the configured models. Refuses to
 * overwrite it without --force. Prints table and column names only, never a row value (FR-018).
 */
class ClassifyCommand extends Command
{
    protected $signature = 'gate:classify {--force : Overwrite an existing app/Gate/classification.php}';

    protected $description = 'Write a draft classification catalog from the configured models; it enables nothing';

    public function handle(DraftWriter $writer): int
    {
        $target = base_path('app/Gate/classification.php');

        if (is_file($target) && ! $this->option('force')) {
            $this->error('app/Gate/classification.php already exists. Use --force to overwrite it.');

            return self::FAILURE;
        }

        /** @var list<class-string<Model>> $models */
        $models = array_values(array_filter(
            (array) config('context-gate.sources.eloquent.models', []),
            static fn (mixed $model): bool => is_string($model) && is_a($model, Model::class, true),
        ));

        // SchemaSource answers whether each model is configured and has a table. Its results carry no column names
        // for an empty catalog, so the names come from the schema directly.
        $source = new SchemaSource(new Catalog([]), $models);
        $tables = [];

        foreach ($models as $model) {
            $result = $source->read(new SourceRequest(['model' => $model]));

            if (! $result->ok) {
                continue;
            }

            $table = (string) $result->data['table'];
            $tables[$table] = array_values(array_map(static fn (array $column): string => (string) $column['name'], Schema::getColumns($table)));
        }

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }

        file_put_contents($target, $writer->render($tables));

        $this->info('Wrote app/Gate/classification.php as a draft. Remove its markers and save it to enable it.');

        return self::SUCCESS;
    }
}
