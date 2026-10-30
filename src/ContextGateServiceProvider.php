<?php

declare(strict_types=1);

namespace ContextGate;

use Illuminate\Support\ServiceProvider;
use Monolog\Handler\StreamHandler;

class ContextGateServiceProvider extends ServiceProvider
{
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
}
