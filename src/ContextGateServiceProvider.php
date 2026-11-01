<?php

declare(strict_types=1);

namespace ContextGate;

use ContextGate\Console\AuditCommand;
use ContextGate\Console\CanaryCommand;
use ContextGate\Console\CheckCommand;
use ContextGate\Console\ClassifyCommand;
use ContextGate\Console\FingerprintCommand;
use ContextGate\Console\InstallCommand;
use ContextGate\Console\PreviewCommand;
use ContextGate\Console\ScanCommand;
use ContextGate\Console\TokenCommand;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\RuleHash;
use ContextGate\Http\HttpRoute;
use ContextGate\Policy\ToolPolicy;
use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Privacy\Redactor;
use ContextGate\Settings\ActiveSettings;
use ContextGate\Settings\EffectiveSettings;
use ContextGate\Server\GateServer;
use ContextGate\Server\Tool;
use ContextGate\Source\Fetch\DnsResolver;
use ContextGate\Source\Fetch\HttpTransport;
use ContextGate\Source\Fetch\Resolver;
use ContextGate\Source\Fetch\Transport;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Monolog\Handler\StreamHandler;

class ContextGateServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Mcp::local('context-gate', GateServer::class);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // The guard and the refusals are registered on every boot. The route is registered whether or not enabled is
        // true (the flag is read at request time and the gate answers as if no tool exists); only a cached route
        // table skips it.
        HttpRoute::registerAuth();

        if (! app()->routesAreCached()) {
            HttpRoute::register();
        }

        // Lazy: hashing instantiates every configured tool and contract, so it runs on first use, not on every request.
        $this->app->singleton('context-gate.rules', static fn (): array => self::ruleHashes());

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/context-gate.php' => config_path('context-gate.php')], 'context-gate-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'context-gate-migrations');
            $this->commands([AuditCommand::class, CanaryCommand::class, CheckCommand::class, ClassifyCommand::class, FingerprintCommand::class, InstallCommand::class, PreviewCommand::class, ScanCommand::class, TokenCommand::class]);
        }
    }

    /**
     * Rule hashes for receipts (research R11), computed at boot. Canonical forms: each tool's description, each
     * contract's class and ordered field list (name, form, options), and the classification map (table, column,
     * class) with the ceiling and limits. Maps are key-sorted so config order does not change a hash. Each canonical
     * form is a JSON string.
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

            $tools[$name] = (app($class))->description();

            $contract = $entry['contract'] ?? null;

            if (! is_string($contract) || ! is_a($contract, ContextContract::class, true)) {
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
            // The ceiling and the limits are in the classification hash: they decide what a receipt's rules allow out.
            'classification' => RuleHash::of(self::canonical([
                'classification' => (array) config('context-gate.classification', []),
                // Effective values, so a profile switch changes the hash even when the raw limits config is unchanged.
                'firewall' => ['ceiling' => config('context-gate.firewall.ceiling'), 'limits' => EffectiveSettings::for((array) config('context-gate', []), (string) config('app.env', ''))],
            ])),
        ];
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/context-gate.php', 'context-gate');

        // stdout belongs to the MCP protocol; receipts and diagnostics go to stderr only.
        // A channel the host already defined under this name is kept; a fresh install gets the stderr stream.
        $channel = 'logging.channels.'.config('context-gate.channel');

        if (config($channel) === null) {
            config()->set($channel, [
                'driver' => 'monolog',
                'handler' => StreamHandler::class,
                'with' => ['stream' => 'php://stderr'],
            ]);
        }

        $this->app->singleton('context-gate.caller', fn (): string => function_exists('posix_geteuid') && ($user = posix_getpwuid(posix_geteuid())) ? $user['name'] : 'unknown');

        // Extension points: a host rebinds these to swap behaviour. Not singletons: Pseudonymizer keeps static session state (not per-instance), so a fresh instance per resolve is fine.
        $this->app->bind(Pseudonymizer::class);
        $this->app->bind(Redactor::class);
        $this->app->bind(ToolPolicy::class);

        $this->app->bind(Resolver::class, DnsResolver::class);
        $this->app->bind(Transport::class, HttpTransport::class);

        // spec 007 R8: the document is validated once, on first use, and every call reads that result.
        $this->app->singleton(ActiveSettings::class, static fn (): ActiveSettings => new ActiveSettings((array) config('context-gate', []), (string) config('app.env', '')));
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
