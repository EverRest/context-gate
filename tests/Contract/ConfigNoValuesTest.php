<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Server\SourceTools;
use ContextGate\Source\ConfigSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

/** FR-007, SC-001: a seeded config value never appears in the source response. */
class ConfigNoValuesTest extends TestCase
{
    use GateCallHarness;

    private const SEEDED = 'FAKE_FIXTURE_ONLY';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.stripe.secret', self::SEEDED);
        config()->set('database.password', self::SEEDED);
        config()->set('app.items', [self::SEEDED]);
        config()->set('context-gate.sources.config.env_map', [
            'services.stripe.secret' => 'STRIPE_SECRET',
            'database.password' => 'DB_PASSWORD',
        ]);
    }

    public function test_seeded_value_is_absent_from_the_whole_response(): void
    {
        $result = (new ConfigSource())->read(new SourceRequest([]));

        $this->assertTrue($result->ok);
        $this->assertStringNotContainsString(self::SEEDED, (string) json_encode($result));
    }

    public function test_seeded_value_is_absent_from_the_tool_response_and_the_receipt(): void
    {
        Gate::define('source.allow', fn (): bool => true);
        $this->recordLines();
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.classification' => ['source_config_keys' => ['key' => 'internal', 'type' => 'internal', 'env' => 'internal']]]);
        config(['context-gate.callers.alice.tools' => ['config.keys']]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'config.keys' => ['enabled' => true, 'gate' => 'source.allow'] + SourceTools::entries()['config.keys'],
        ]]);

        $result = $this->callTool('config.keys', []);

        $this->assertFalse($result['isError']);
        $this->assertStringContainsString('services.stripe.secret', (string) json_encode($result));
        $this->assertStringNotContainsString(self::SEEDED, (string) json_encode($result));
        $this->assertNotEmpty($this->lines);
        $this->assertStringNotContainsString(self::SEEDED, implode("\n", $this->lines));
    }
}
