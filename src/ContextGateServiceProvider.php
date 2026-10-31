<?php

declare(strict_types=1);

namespace ContextGate;

use ContextGate\Console\CheckCommand;
use ContextGate\Console\FingerprintCommand;
use ContextGate\Console\InstallCommand;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\RuleHash;
use ContextGate\Server\GateServer;
use ContextGate\Server\Tool;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Monolog\Handler\StreamHandler;

class ContextGateServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Mcp::local('context-gate', GateServer::class);

        $this->app->instance('context-gate.rules', self::ruleHashes());

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class, FingerprintCommand::class, InstallCommand::class]);
        }
    }

    /**
     * Rule hashes for receipts (research R11), computed at boot. Canonical forms: each tool's description, each
     * contract's class and ordered field list (name, form, options), and the classification map (table, column,
     * class). Maps are key-sorted so config order does not change a hash. Each canonical form is a JSON string.
     *
     * @return array{tool: string, contract: string, classification: string}
     */
    public static function ruleHashes(): array
    {
        $tools = [];
        $contracts = [];

        /** @var array<string, array<string, mixed>> $configured */
        $configured = config('context-gate.tools', []);

        foreach ($configured as $name => $entry) {
            $class = $entry['class'] ?? null;

            if (! is_string($class) || ! is_a($class, Tool::class, true)) {
                continue;
            }

            $tools[$name] = (new $class())->description();

            $contract = $entry['contract'] ?? null;

            if (! is_string($contract) || ! is_a($contract, ContextContract::class, true)) {
                continue;
            }

            // Fields stay a list: output order is part of the contract, so it is part of the hash.
            $fields = [];

            foreach ((new $contract())->fields() as $column => $field) {
                $fields[] = ['column' => $column, 'form' => $field->form, 'options' => $field->options];
            }

            $contracts[$name] = ['contract' => $contract, 'fields' => $fields];
        }

        ksort($tools);
        ksort($contracts);

        return [
            'tool' => RuleHash::of(self::canonical($tools)),
            'contract' => RuleHash::of(self::canonical($contracts)),
            'classification' => RuleHash::of(self::canonical((array) config('context-gate.classification', []))),
        ];
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/context-gate.php', 'context-gate');

        // stdout belongs to the MCP protocol; receipts and diagnostics go to stderr only.
        // Intentional: the context-gate channel always writes to stderr, replacing any host channel with the same name.
        config()->set('logging.channels.'.config('context-gate.channel'), [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => ['stream' => 'php://stderr'],
        ]);

        $this->app->singleton('context-gate.caller', fn (): string => function_exists('posix_geteuid') && ($user = posix_getpwuid(posix_geteuid())) ? $user['name'] : 'unknown');
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
