<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\RoleFixture;
use ContextGate\Server\SourceTools;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

/**
 * Quickstart bug: with more than 248 keys the per-call truncation (Budget::fit keeps rows in order) dropped the
 * env-mapped rows that came last. ConfigSource lists the env-mapped rows first, so only unmapped rows are cut.
 */
class ConfigEnvMappedTruncationTest extends TestCase
{
    use GateCallHarness;

    public function test_a_config_with_more_than_248_keys_still_lists_every_env_mapped_key(): void
    {
        $bulk = [];
        for ($i = 0; $i < 400; $i++) {
            $bulk['entry_number_' . str_pad((string) $i, 4, '0', STR_PAD_LEFT)] = 'FAKE_FIXTURE_ONLY';
        }
        config()->set('bulk', $bulk);
        config()->set('zz_mapped.host', 'localhost');
        config()->set('context-gate.sources.config.env_map', ['zz_mapped.host' => 'APP_MAPPED_HOST']);

        Gate::define('source.allow', fn (): bool => true);
        $this->recordLines();
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.classification' => ['source_config_keys' => ['key' => 'internal', 'type' => 'internal', 'env' => 'internal']]]);
        config(RoleFixture::stdio('alice', ['config.keys']));
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'config.keys' => ['enabled' => true, 'gate' => 'source.allow'] + SourceTools::entries()['config.keys'],
        ]]);

        $result = $this->callTool('config.keys', []);
        $rows = json_decode((string) $result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($rows);
        $this->assertContains(['key' => 'zz_mapped.host', 'type' => 'string', 'env' => 'env:APP_MAPPED_HOST'], $rows);
    }
}
