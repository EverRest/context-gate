<?php

declare(strict_types=1);

namespace ContextGate\Contract;

use ContextGate\Classification\Catalog;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Checks a contract against the catalog on load (FR-004). A column without a class fails the load.
 */
final class ContractValidator
{
    public function validate(ContextContract $contract, Catalog $catalog): void
    {
        $table = $this->tableOf($contract);

        foreach (array_keys($contract->fields()) as $column) {
            if ($catalog->classOf($table, $column) === null) {
                throw new InvalidArgumentException(sprintf(
                    'Contract %s refers to %s.%s, which has no classification in the catalog.',
                    $contract::class,
                    $table,
                    $column,
                ));
            }
        }
    }

    public function tableOf(ContextContract $contract): string
    {
        $model = $contract->model();

        /** @var Model $instance */
        $instance = new $model();

        return $instance->getTable();
    }
}
