<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit;

use ContextGate\Classification\Ceiling;
use ContextGate\ContextGateServiceProvider;
use ContextGate\Settings\RuleHashes;
use ContextGate\Tests\TestCase;
use Illuminate\Support\ServiceProvider;

/** The package defines its log channel and guard only when the host has not, and does no rule hashing on boot. */
class ProviderHostOwnershipTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('logging.channels.context-gate', ['driver' => 'single', 'path' => '/tmp/host-owned.log']);
        $app['config']->set('auth.guards.context-gate', ['driver' => 'host-guard', 'provider' => 'users']);
    }

    public function test_a_host_defined_channel_is_left_untouched(): void
    {
        $this->assertSame(['driver' => 'single', 'path' => '/tmp/host-owned.log'], config('logging.channels.context-gate'));
    }

    public function test_a_host_defined_guard_is_left_untouched(): void
    {
        $this->assertSame('host-guard', config('auth.guards.context-gate.driver'));
    }

    public function test_rule_hashes_are_not_computed_until_first_use(): void
    {
        $this->assertFalse(app()->resolved(RuleHashes::class));
        $this->assertSame(RuleHashes::forCall(Ceiling::Internal), RuleHashes::forCall(Ceiling::Internal));
    }

    public function test_config_and_migrations_have_publish_tags(): void
    {
        $config = ServiceProvider::pathsToPublish(ContextGateServiceProvider::class, 'context-gate-config');
        $migrations = ServiceProvider::pathsToPublish(ContextGateServiceProvider::class, 'context-gate-migrations');

        $this->assertSame([config_path('context-gate.php')], array_values($config));
        $this->assertSame([database_path('migrations')], array_values($migrations));
    }
}
