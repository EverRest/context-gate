<?php

declare(strict_types=1);

namespace ContextGate\Readiness\Checks;

use ContextGate\Contract\ContractValidator;
use ContextGate\Readiness\Check;
use ContextGate\Readiness\CheckResult;
use ContextGate\Readiness\ReadinessContext;

/**
 * The catalog classifies every contract column and holds no draft marker.
 *
 * @internal May change without notice.
 */
final class CatalogCheck implements Check
{
    private const DRAFT_FILE = 'app/Gate/classification.php';

    private const MARKERS = ['// suggested', '// needs decision'];

    public function name(): string
    {
        return self::class;
    }

    public function run(ReadinessContext $ctx): CheckResult
    {
        $draft = $this->draftMarkerLine();

        if ($draft !== null) {
            return CheckResult::fail(self::DRAFT_FILE, "line {$draft} still has a draft marker", $this->fixHint());
        }

        $validator = new ContractValidator();

        foreach ($ctx->contracts as $contract) {
            $table = $validator->tableOf($contract);

            foreach (array_keys($contract->fields()) as $column) {
                if ($ctx->catalog->classOf($table, (string) $column) === null) {
                    return CheckResult::fail("classification.{$table}.{$column}", $column.' is not classified in the catalog', $this->fixHint());
                }
            }
        }

        return CheckResult::pass('classification', 'every contract column is classified and no draft marker remains');
    }

    /** The first line of the catalog draft that holds a draft marker, or null. */
    private function draftMarkerLine(): ?int
    {
        $path = base_path(self::DRAFT_FILE);

        if (! is_file($path)) {
            return null;
        }

        foreach (file($path) ?: [] as $index => $line) {
            foreach (self::MARKERS as $marker) {
                if (str_contains($line, $marker)) {
                    return $index + 1;
                }
            }
        }

        return null;
    }

    public function fixHint(): string
    {
        return 'replace each draft marker in app/Gate/classification.php with the chosen class, then run gate:check again';
    }
}
