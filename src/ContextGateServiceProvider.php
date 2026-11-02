<?php

declare(strict_types=1);

namespace ContextGate;

use ContextGate\Console\AuditCommand;
use ContextGate\Console\CanaryCommand;
use ContextGate\Console\CheckCommand;
use ContextGate\Console\ConfigExportCommand;
use ContextGate\Console\ConfigImportCommand;
use ContextGate\Console\ConfigRollbackCommand;
use ContextGate\Console\ClassifyCommand;
use ContextGate\Console\FingerprintCommand;
use ContextGate\Console\InstallCommand;
use ContextGate\Console\PreviewCommand;
use ContextGate\Console\RunbookCommand;
use ContextGate\Console\ScanCommand;
use ContextGate\Console\SimulateCommand;
use ContextGate\Console\TokenCommand;
use ContextGate\Http\HttpRoute;
use ContextGate\Contracts\AuthorizesTools;
use ContextGate\Contracts\IssuesPseudonyms;
use ContextGate\Contracts\RedactsText;
use ContextGate\Policy\ToolPolicy;
use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Privacy\Redactor;
use ContextGate\Settings\ActiveSettings;
use ContextGate\Settings\ConfigOverlay;
use ContextGate\Settings\RuleHashes;
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

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/context-gate.php' => config_path('context-gate.php')], 'context-gate-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'context-gate-migrations');
            $this->commands([AuditCommand::class, CanaryCommand::class, CheckCommand::class, ClassifyCommand::class, FingerprintCommand::class, InstallCommand::class, PreviewCommand::class, ScanCommand::class, SimulateCommand::class, TokenCommand::class, ConfigImportCommand::class, ConfigExportCommand::class, ConfigRollbackCommand::class, RunbookCommand::class]);
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/context-gate.php', 'context-gate');

        // The applied version is merged over the file config once, before ActiveSettings is first resolved.
        $channel = 'logging.channels.'.ConfigOverlay::install($this->app);

        // stdout belongs to the MCP protocol; receipts and diagnostics go to stderr only.
        // A channel the host already defined under this name is kept; a fresh install gets the stderr stream.

        if (config($channel) === null) {
            config()->set($channel, [
                'driver' => 'monolog',
                'handler' => StreamHandler::class,
                'with' => ['stream' => 'php://stderr'],
            ]);
        }

        $this->app->singleton('context-gate.caller', fn (): string => function_exists('posix_geteuid') && ($user = posix_getpwuid(posix_geteuid())) ? $user['name'] : 'unknown');

        // Extension points: a host rebinds the interface to swap behaviour. Not singletons: Pseudonymizer keeps static session state (not per-instance), so a fresh instance per resolve is fine.
        // Built directly, not by a class-string binding: a class-string would resolve through any host binding of the concrete name.
        $this->app->bind(RedactsText::class, fn ($app) => $app->build(Redactor::class));
        $this->app->bind(IssuesPseudonyms::class, fn ($app) => $app->build(Pseudonymizer::class));
        $this->app->bind(AuthorizesTools::class, fn ($app) => $app->build(ToolPolicy::class));

        $this->app->bind(Resolver::class, DnsResolver::class);
        $this->app->bind(Transport::class, HttpTransport::class);

        // The document is validated once, on first use, and every call reads that result.
        $this->app->singleton(ActiveSettings::class, fn ($app): ActiveSettings => ActiveSettings::fromApp($app));

        // Lazy: hashing instantiates every configured tool and contract, so it runs on first use, not on every request.
        $this->app->singleton(RuleHashes::class);
    }
}
