<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Pipeline\Caps;
use ContextGate\Settings\ActiveSettings;
use ContextGate\Tests\Contract\GateCallHarness;
use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * spec 009 US1, SC-002, SC-003: gate:check and the runtime read the same validated settings. An invalid value fails the
 * check on its key and refuses every call; a valid document's effective values are the ones the check reports.
 */
class CheckRuntimeAgreeTest extends TestCase
{
    use GateCallHarness;

    /**
     * One invalid document per schema key. The last flag is false when the server cannot start with the value (a tool
     * entry without a usable class stops the server at boot), so only the check is asserted for it.
     *
     * @return iterable<string, array{list<string>, mixed, bool}>
     */
    public static function invalidDocuments(): iterable
    {
        yield 'enabled' => [['enabled'], 'yes', true];
        yield 'profile' => [['profile'], 'weak', true];
        yield 'callers' => [['callers', 'alice', 'tools'], 'probe', true];
        yield 'canaries' => [['canaries', 'values'], 'FAKE_FIXTURE_ONLY', true];
        yield 'firewall ceiling' => [['firewall', 'ceiling'], 'secret', true];
        yield 'firewall limit above its cap' => [['firewall', 'limits', 'per_call_bytes'], Caps::PER_CALL + 1, true];
        yield 'firewall limit below one' => [['firewall', 'limits', 'calls_per_minute'], 0, true];
        yield 'firewall limit wrong type' => [['firewall', 'limits', 'per_day_bytes'], 'big', true];
        yield 'classification not a map' => [['classification'], 'internal', true];
        yield 'classification class' => [['classification', 'customers', 'status'], 'top', true];
        yield 'http path' => [['http', 'path'], 123, true];
        yield 'http origins' => [['http', 'origins'], 'https://app.test', true];
        yield 'http refusals' => [['http', 'refusals_per_minute'], 'many', true];
        yield 'channel' => [['channel'], 5, true];
        yield 'cache store' => [['cache_store'], 5, true];
        yield 'log level' => [['log_level'], 'loud', true];
        yield 'routes exclude' => [['sources', 'routes', 'exclude'], 'api/*', true];
        yield 'eloquent models' => [['sources', 'eloquent', 'models'], 1, true];
        yield 'config env map' => [['sources', 'config', 'env_map'], ['services.x'], true];
        yield 'docs paths' => [['sources', 'docs', 'paths'], 'docs/', true];
        yield 'openapi paths' => [['sources', 'openapi', 'paths'], 'openapi/', true];
        yield 'openapi allowlist' => [['sources', 'openapi', 'allowlist'], 'api.example.test', true];
        yield 'openapi examples' => [['sources', 'openapi', 'examples'], 'keep', true];
        yield 'openapi access' => [['sources', 'openapi', 'access'], 'write', true];
        yield 'openapi timeout' => [['sources', 'openapi', 'timeout_seconds'], 'slow', true];
        yield 'openapi max bytes' => [['sources', 'openapi', 'max_bytes'], 'big', true];
        yield 'tool enabled' => [['tools', 'probe', 'enabled'], 'yes', true];
        yield 'tool gate' => [['tools', 'probe', 'gate'], 5, true];
        yield 'tool risk' => [['tools', 'probe', 'risk'], 'extreme', true];
        yield 'tool class' => [['tools', 'probe', 'class'], 123, false];
        yield 'runbooks' => [['runbooks', 'mixed'], 5, true];
        yield 'unknown key' => [['redaction'], 'off', true];
    }

    /** @param list<string> $path */
    #[DataProvider('invalidDocuments')]
    public function test_gate_check_names_the_key_and_every_call_is_refused(array $path, mixed $value, bool $callable): void
    {
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public', 'note' => 'internal', 'email' => 'personal']]);
        $document = (array) config('context-gate');
        $cursor = &$document;

        foreach ($path as $key) {
            $cursor[$key] ??= [];
            $cursor = &$cursor[$key];
        }

        $cursor = $value;
        unset($cursor);
        config(['context-gate' => $document]);

        $this->assertSame(1, Artisan::call('gate:check'));
        $this->assertStringContainsString('context-gate.'.implode('.', $path), Artisan::output());

        if ($callable) {
            $this->assertSame([], $this->listedTools());
            $result = $this->callTool('probe', ['text' => 'x']);

            $this->assertTrue($result['isError']);
            $this->assertFalse(ProbeTool::$called);
            $this->assertSame('config.invalid', $this->lastReceipt()['reason']);
        }
    }

    /** @return list<string> the tool names in tools/list */
    private function listedTools(): array
    {
        $raw = $this->send((string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], JSON_THROW_ON_ERROR))[0];

        return array_column(json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['result']['tools'], 'name');
    }

    /** @return iterable<string, array{array<string, mixed>}> three valid documents: strict, balanced, explicit limits */
    public static function validDocuments(): iterable
    {
        yield 'strict' => [['profile' => 'strict', 'app.env' => 'testing']];
        yield 'balanced' => [['profile' => 'balanced', 'app.env' => 'local']];
        yield 'explicit limits' => [['firewall' => ['limits' => ['per_call_bytes' => 1000]], 'app.env' => 'local']];
    }

    /** @param array<string, mixed> $settings */
    #[DataProvider('validDocuments')]
    public function test_the_effective_values_gate_check_reports_are_the_values_the_runtime_uses(array $settings): void
    {
        $env = $settings['app.env'];
        unset($settings['app.env']);
        config(['app.env' => $env, 'context-gate' => array_replace_recursive((array) config('context-gate'), $settings)]);

        Artisan::call('gate:check', ['--json' => true]);
        $effective = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)['effective'];
        $settingsInUse = app(ActiveSettings::class);

        $this->assertTrue($settingsInUse->valid());
        $this->assertEquals($settingsInUse->limits(), $effective['limits']);
        $this->assertSame($settingsInUse->ceiling()->value, $effective['ceiling']);
    }
}
