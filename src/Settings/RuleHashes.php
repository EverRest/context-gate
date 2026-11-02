<?php

declare(strict_types=1);

namespace ContextGate\Settings;

use ContextGate\Classification\Ceiling;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\RuleHash;
use ContextGate\Server\Tool;
use ContextGate\Server\ToolRegistry;

/**
 * The rule hashes a receipt records. Canonical forms: each tool's description, each contract's class and ordered field
 * list (name, form, options), and the effective classification with the call's ceiling and the effective limits. Maps
 * are key-sorted so config order does not change a hash. Hashes are computed from effective values, never raw config.
 * The tool and contract hashes are computed once per settings instance; the classification hash once per ceiling.
 *
 * @internal May change without notice.
 */
final class RuleHashes
{
    private ?ActiveSettings $settings = null;

    /** @var array{tool: string, contract: string}|null */
    private ?array $rules = null;

    /** @var array<string, string> ceiling value => classification hash */
    private array $classification = [];

    /**
     * @return array{tool: string, contract: string, classification: string}
     */
    public static function forCall(?Ceiling $ceiling): array
    {
        return app(self::class)->hashes($ceiling);
    }

    /** @return array{tool: string, contract: string, classification: string} */
    private function hashes(?Ceiling $ceiling): array
    {
        $settings = app(ActiveSettings::class);

        if ($this->settings !== $settings) {
            $this->settings = $settings;
            $this->rules = null;
            $this->classification = [];
        }

        $this->rules ??= self::toolAndContract($settings);
        $key = $ceiling === null ? '' : $ceiling->value;

        // The ceiling and the limits are in the classification hash: they decide what a receipt's rules allow out.
        $this->classification[$key] ??= RuleHash::of(self::canonical([
            'classification' => $settings->classification(),
            'firewall' => ['ceiling' => $ceiling?->value, 'limits' => $settings->limits()],
        ]));

        return ['tool' => $this->rules['tool'], 'contract' => $this->rules['contract'], 'classification' => $this->classification[$key]];
    }

    /** @return array{tool: string, contract: string} */
    private static function toolAndContract(ActiveSettings $settings): array
    {
        $tools = [];
        $contracts = [];

        foreach ($settings->tools() as $name => $entry) {
            $class = $entry->class;

            if (! is_a($class, Tool::class, true)) {
                continue;
            }

            $tools[$name] = ToolRegistry::instantiate((string) $name, $class)->description();

            $contract = $entry->contract;

            if ($contract === null || ! is_a($contract, ContextContract::class, true)) {
                continue;
            }

            // Fields stay a list: output order is part of the contract, so it is part of the hash.
            $fields = [];

            foreach (app($contract)->fields() as $column => $field) {
                $fields[] = ['column' => $column, 'form' => $field->form, 'options' => $field->options];
            }

            $contracts[$name] = ['contract' => $contract, 'fields' => $fields];
        }

        ksort($tools);
        ksort($contracts);

        return [
            'tool' => RuleHash::of(self::canonical($tools)),
            'contract' => RuleHash::of(self::canonical($contracts)),
        ];
    }

    /**
     * Key-sorted at every depth, so equal maps give equal JSON. Lists keep their order.
     *
     * @param array<array-key, mixed> $value
     */
    private static function canonical(array $value): string
    {
        return json_encode(self::sortKeys($value), JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
