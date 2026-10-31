<?php

declare(strict_types=1);

namespace ContextGate\Contract;

use ContextGate\Classification\Catalog;
use ContextGate\Classification\Ceiling;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Checks a contract against the catalog on load. A column without a class fails the load (FR-004),
 * a secret column never loads (FR-005), and a personal column loads only as a pseudonym, aggregate or bucket (FR-006).
 */
final class ContractValidator
{
    /** @var list<string> forms that may carry a personal column */
    private const PERSONAL_FORMS = [Field::PSEUDONYM, Field::AGGREGATE, Field::BUCKET];

    public function validate(ContextContract $contract, Catalog $catalog): void
    {
        $table = $this->tableOf($contract);

        foreach ($contract->fields() as $column => $field) {
            $class = $catalog->classOf($table, $column);

            if ($class === null) {
                throw new InvalidArgumentException(sprintf(
                    'Contract %s refers to %s.%s, which has no classification in the catalog.',
                    $contract::class,
                    $table,
                    $column,
                ));
            }

            if ($class === Ceiling::Secret) {
                throw new InvalidArgumentException(sprintf(
                    'Contract %s lists %s.%s, which is classified secret; a secret field never leaves the gate (FR-005).',
                    $contract::class,
                    $table,
                    $column,
                ));
            }

            if ($class === Ceiling::Personal && ! in_array($field->form, self::PERSONAL_FORMS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Contract %s lists %s.%s, which is classified personal, in the %s form; a personal field needs a pseudonym, aggregate or bucket (FR-006).',
                    $contract::class,
                    $table,
                    $column,
                    $field->form,
                ));
            }
        }

        foreach ($contract->fields() as $field) {
            if ($field->form !== Field::RELATION) {
                continue;
            }

            /** @var class-string<ContextContract> $relatedClass */
            $relatedClass = $field->options['contract'];
            $related = new $relatedClass();

            if ($this->hasRelation($related)) {
                throw new InvalidArgumentException(sprintf(
                    'contract.depth: Contract %s relates to %s, which has its own relation; relations go one level only (FR-011).',
                    $contract::class,
                    $relatedClass,
                ));
            }

            // Related rows are not normalised, redacted or quarantined: a text field there would leave unscanned.
            foreach ($related->fields() as $relatedColumn => $relatedField) {
                if ($relatedField->form === Field::TEXT) {
                    throw new InvalidArgumentException(sprintf(
                        'contract.related_text: Contract %s relates to %s, whose field %s is text; related rows carry no text fields.',
                        $contract::class,
                        $relatedClass,
                        $relatedColumn,
                    ));
                }
            }

            $this->validate($related, $catalog);
        }
    }

    private function hasRelation(ContextContract $contract): bool
    {
        foreach ($contract->fields() as $field) {
            if ($field->form === Field::RELATION) {
                return true;
            }
        }

        return false;
    }

    public function tableOf(ContextContract $contract): string
    {
        $model = $contract->model();

        /** @var Model $instance */
        $instance = new $model();

        return $instance->getTable();
    }
}
