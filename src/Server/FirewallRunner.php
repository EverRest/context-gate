<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Classification\Catalog;
use ContextGate\Classification\Ceiling;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\ContractValidator;
use ContextGate\Contract\Field;
use ContextGate\Pipeline\Budget;
use ContextGate\Pipeline\CanaryCheck;
use ContextGate\Pipeline\ClassifyCheck;
use ContextGate\Pipeline\Normalizer;
use ContextGate\Pipeline\Payload;
use ContextGate\Pipeline\Projector;
use ContextGate\Pipeline\Quarantine;
use ContextGate\Pipeline\Redact;
use ContextGate\Pipeline\Relations;
use ContextGate\Pipeline\Transformer;
use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Privacy\Redactor;
use Illuminate\Database\Eloquent\Model;
use Throwable;
use UnexpectedValueException;

/** The firewall stages 2 to 10 of R1 (contracts/firewall-pipeline.md), run on one handler response. */
final class FirewallRunner
{
    /**
     * Stages 2 to 10 of R1 in their fixed order. Returns the payload, or the reason the call stops: a canary hit
     * or a failed normalisation or pseudonym transform.
     *
     * @param list<array<string, mixed>> $rows the handler output, before any projection
     */
    public function firewall(Caller $caller, string $name, ContextContract $contract, Ceiling $ceiling, Budget $budget, array $rows): Payload|ReasonCode
    {
        $fields = $contract->fields();
        $table = (new ContractValidator())->tableOf($contract);
        /** @var Model $model */
        $model = new ($contract->model())();
        $key = $model->getKeyName();
        $textFields = array_keys(array_filter($fields, static fn (Field $field): bool => $field->form === Field::TEXT));
        $scope = "session:{$caller->id}";

        // The record identifiers are read before the projection, which may drop the id column the pseudonyms need.
        $ids = [];

        foreach ($rows as $index => $row) {
            if (is_int($row[$key] ?? null) || is_string($row[$key] ?? null)) {
                $ids[$index] = $row[$key];
            }
        }

        /** @var array<string, array<string, mixed>> $map */
        $map = config('context-gate.classification', []);
        $catalog = new Catalog($map);
        $identity = static fn (Payload $next): Payload => $next;

        $this->observe('classify');
        $payload = (new ClassifyCheck($catalog, $ceiling, $table, $fields))->handle(new Payload($rows, array_keys($fields)), $identity);

        $this->observe('project');
        $payload = (new Projector())->handle($payload, $identity);

        $this->observe('relations');

        // A related day or week pseudonym would need a key that the relation does not carry: refused, not emitted.
        if ($this->relatesToDayOrWeekPseudonym($fields)) {
            return ReasonCode::PseudonymUnknown;
        }

        $payload = (new Relations($fields, $catalog, $ceiling, new Pseudonymizer(), $scope, $caller->id))->handle($payload, $identity);

        $this->observe('transform');
        try {
            $payload = $this->transform($payload, $fields, $name, $caller, $table, $key, $ids);
        } catch (Throwable) {
            return ReasonCode::PseudonymKeyFail;
        }

        $this->observe('normalize');
        try {
            $payload = (new Normalizer($textFields))->handle($payload, $identity);
        } catch (Throwable) {
            return ReasonCode::PolicyDenied;
        }

        $this->observe('redact');
        $payload = (new Redact(new Redactor(), $textFields))->handle($payload, $identity);

        $this->observe('canary');
        /** @var list<string> $canaries */
        $canaries = config('context-gate.canaries.values', []);
        $payload = (new CanaryCheck($canaries))->handle($payload, $identity);

        if (in_array(CanaryCheck::FLAG, $payload->flags, true)) {
            return ReasonCode::CanaryHit;
        }

        $this->observe('quarantine');
        $payload = (new Quarantine($name, $textFields))->handle($payload, $identity);

        $this->observe('budget');

        return $budget->handle($payload, $identity);
    }

    /** Test seam: a bound observer is told each stage as it starts. Not bound in production. */
    public function observe(string $stage): void
    {
        if (app()->bound('context-gate.stage_observer')) {
            app('context-gate.stage_observer')($stage);
        }
    }

    /** @return array{list<array<string, mixed>>, bool} the rows, and whether the handler returned a list */
    public function decode(string $text): array
    {
        $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new UnexpectedValueException('Handler output is not JSON.');
        }

        $isList = array_is_list($decoded);
        $rows = $isList ? $decoded : [$decoded];

        if (array_filter($rows, is_array(...)) !== $rows) {
            throw new UnexpectedValueException('Handler output rows must be JSON objects.');
        }

        /** @var list<array<string, mixed>> $rows */
        return [$rows, $isList];
    }

    /** The scope as stored and resolved: session:{caller}, or {day|week}:{tool} with the caller bound by the store. */
    public static function scopeKey(Field $field, string $name, Caller $caller): string
    {
        /** @var string $scope */
        $scope = $field->options['scope'] ?? 'session';

        return $scope === 'session' ? "session:{$caller->id}" : "{$scope}:{$name}";
    }

    /**
     * Stage 5: one transformer per pseudonym, bucket, date or aggregate field, so each pseudonym gets its own
     * scope key. Plain, text and relation fields pass through.
     *
     * @param array<string, Field> $fields
     * @param array<int, int|string> $ids record identifiers by row position
     */
    private function transform(Payload $payload, array $fields, string $name, Caller $caller, string $table, string $key, array $ids): Payload
    {
        foreach ($fields as $column => $field) {
            if (! in_array($column, $payload->fields, true) || in_array($field->form, [Field::PLAIN, Field::TEXT, Field::RELATION], true)) {
                continue;
            }

            $payload = (new Transformer(new Pseudonymizer(), $table, self::scopeKey($field, $name, $caller), [$column => $field], $key, $caller->id, $ids))
                ->handle($payload, static fn (Payload $next): Payload => $next);
        }

        return $payload;
    }

    /** @param array<string, Field> $fields the parent contract's fields */
    private function relatesToDayOrWeekPseudonym(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($field->form !== Field::RELATION) {
                continue;
            }

            /** @var class-string<ContextContract> $relatedClass */
            $relatedClass = $field->options['contract'];

            foreach ((new $relatedClass())->fields() as $relatedField) {
                if ($relatedField->form === Field::PSEUDONYM && ($relatedField->options['scope'] ?? 'session') !== 'session') {
                    return true;
                }
            }
        }

        return false;
    }
}
