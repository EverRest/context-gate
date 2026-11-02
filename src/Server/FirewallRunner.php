<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Settings\ActiveSettings;
use Closure;
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
use ContextGate\Contracts\IssuesPseudonyms;
use ContextGate\Contracts\RedactsText;
use ContextGate\Support\StoreUnavailable;
use Illuminate\Database\Eloquent\Model;
use Throwable;
use UnexpectedValueException;

/**
 * The firewall stages 2 to 10, run on one handler response.
 *
 * @internal May change without notice.
 */
final class FirewallRunner
{
    /** @param (Closure(string): void)|null $mark receives each stage name as it starts; the order test records them */
    public function __construct(private readonly ?Closure $mark = null)
    {
    }

    /**
     * Stages 2 to 10 in their fixed order. Returns the payload, or the reason the call stops: a canary hit
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

        $catalog = new Catalog(app(ActiveSettings::class)->classification());
        $identity = static fn (Payload $next): Payload => $next;

        $this->stage('classify');
        $payload = (new ClassifyCheck($catalog, $ceiling, $table, $fields))->handle(new Payload($rows, array_keys($fields)), $identity);

        $this->stage('project');
        $payload = (new Projector())->handle($payload, $identity);

        $this->stage('relations');

        $payload = (new Relations($fields, $catalog, $ceiling, app(IssuesPseudonyms::class), $scope, $caller->id))->handle($payload, $identity);

        // ponytail: work bound, not the truncation. Twice the per-call limit and at most 10 000 rows (the Pseudonymizer's
        // batch ceiling) go on; stage 10 truncates the final rows. A transform that grows rows more than 2x keeps fewer.
        $payload->rows = array_slice($budget->fit($payload->rows, 2)[0], 0, 10000);
        $kept = count($payload->rows);
        $ids = array_filter($ids, static fn (int $index): bool => $index < $kept, ARRAY_FILTER_USE_KEY);

        $this->stage('transform');
        $transformed = $this->transform($payload, $fields, $name, $caller, $table, $key, $ids);

        if ($transformed instanceof ReasonCode) {
            return $transformed;
        }

        $payload = $transformed;
        $this->stage('normalize');
        try {
            $payload = (new Normalizer($payload->fields))->handle($payload, $identity);
        } catch (Throwable $e) {
            Failure::hold($e);

            return ReasonCode::PolicyDenied;
        }

        // A canary inside a text field must be found before the redaction can remove it and hide the signal.
        $this->stage('canary.pre');

        if (in_array(CanaryCheck::FLAG, (new CanaryCheck(Canaries::values()))->handle($payload, $identity)->flags, true)) {
            return ReasonCode::CanaryHit;
        }

        $this->stage('redact');
        $payload = (new Redact(app(RedactsText::class), $textFields))->handle($payload, $identity);

        $this->stage('canary');
        $payload = (new CanaryCheck(Canaries::values()))->handle($payload, $identity);

        if (in_array(CanaryCheck::FLAG, $payload->flags, true)) {
            return ReasonCode::CanaryHit;
        }

        $this->stage('quarantine');
        $payload = (new Quarantine($name, $textFields))->handle($payload, $identity);

        $this->stage('budget');

        return $budget->handle($payload, $identity);
    }

    /**
     * Refusals before the handler runs: a contract that relates to text, to a day or week pseudonym, or one level too
     * deep, and the canary check on the normalised arguments.
     * Returns the reason, or null when the call may go on.
     *
     * @param array<string, mixed> $arguments
     */
    public function argumentsRefusal(array $arguments, string $name, ?ContextContract $contract): ?ReasonCode
    {
        $identity = static fn (Payload $next): Payload => $next;

        if ($contract !== null && ($refusal = RelationRefusal::of($contract->fields())) !== null) {
            return $refusal;
        }

        // A contract that breaks a load rule is a configuration error: it is refused per call, not only warned at boot.
        if ($contract !== null && ContractGuard::violation($contract) !== null) {
            return ReasonCode::ConfigInvalid;
        }

        // Any failure here, a normalisation or an encoding error, refuses the call: the check cannot vouch for it.
        try {
            $checked = (new CanaryCheck(Canaries::values()))->handle(new Payload([Normalizer::tree($arguments)]), $identity);
        } catch (Throwable $e) {
            Failure::hold($e);

            return ReasonCode::PolicyDenied;
        }

        return in_array(CanaryCheck::FLAG, $checked->flags, true) ? ReasonCode::CanaryHit : null;
    }

    /** Marks a stage as it starts. Does nothing unless a mark closure was injected. */
    public function stage(string $name): void
    {
        if ($this->mark !== null) {
            ($this->mark)($name);
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
     * Stage 5: one transformer per pseudonym, bucket, date or aggregate field, each with its own scope key. A failure
     * is pseudonym.keyfail for a pseudonym field only; any other (a bad bucket or date, a missing id) is transform.failed.
     *
     * @param array<string, Field> $fields
     * @param array<int, int|string> $ids record identifiers by row position
     */
    private function transform(Payload $payload, array $fields, string $name, Caller $caller, string $table, string $key, array $ids): Payload|ReasonCode
    {
        foreach ($fields as $column => $field) {
            if (! in_array($column, $payload->fields, true) || in_array($field->form, [Field::PLAIN, Field::TEXT, Field::RELATION], true)) {
                continue;
            }

            // Fail closed: a pseudonym needs the record identifier of every row, or the raw value would have to leave.
            if ($field->form === Field::PSEUDONYM && count($ids) !== count($payload->rows)) {
                return ReasonCode::TransformFailed;
            }

            try {
                $payload = (new Transformer(app(IssuesPseudonyms::class), $table, self::scopeKey($field, $name, $caller), [$column => $field], $key, $caller->id, $ids))
                    ->handle($payload, static fn (Payload $next): Payload => $next);
            } catch (Throwable $e) {
                Failure::hold($e);

                if ($e instanceof StoreUnavailable) {
                    return ReasonCode::StoreUnavailable;
                }

                return $field->form === Field::PSEUDONYM ? ReasonCode::PseudonymKeyFail : ReasonCode::TransformFailed;
            }
        }

        return $payload;
    }
}
