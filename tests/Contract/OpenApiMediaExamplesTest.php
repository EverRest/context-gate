<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Server\SourceTools;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

/** FR-003: the examples of a request body's Media Type reach the caller only through the firewall, or not at all. */
class OpenApiMediaExamplesTest extends TestCase
{
    use GateCallHarness;

    private const SEEDED_EMAIL = 'canary-fixture@example.invalid';

    private const SEEDED_TOKEN = 'FAKE_FIXTURE_ONLY_TOKEN_0001';

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('source.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => ['openapi.endpoints']]);
        config(['context-gate.classification' => ['source_openapi_endpoints' => array_fill_keys(
            ['version', 'method', 'path', 'parameters', 'request_schema', 'examples', 'response_schemas', 'security'],
            'internal',
        )]]);
        config(['context-gate.sources.openapi.paths' => ['openapi/']]);
        $app = $this->app;

        if ($app === null) {
            $this->fail('The application is not booted.');
        }

        $app->setBasePath(dirname(__DIR__) . '/Fixtures');
        $this->recordLines();

        $entries = SourceTools::entries();
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'openapi.endpoints' => ['enabled' => true, 'gate' => 'source.allow'] + $entries['openapi.endpoints'],
        ]]);
    }

    public function test_redacted_mode_returns_the_media_example_with_the_email_as_a_placeholder(): void
    {
        config(['context-gate.sources.openapi.examples' => 'redacted']);

        $result = $this->callTool('openapi.endpoints', ['path' => 'openapi/examples-in-media.json']);
        $text = (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertFalse($result['isError']);
        $this->assertStringNotContainsString(self::SEEDED_EMAIL, $text);
        $this->assertStringContainsString('[EMAIL]', $text);
    }

    public function test_redacted_mode_blocks_the_response_when_a_listed_canary_is_in_a_media_example(): void
    {
        config(['context-gate.sources.openapi.examples' => 'redacted']);
        config(['context-gate.canaries.values' => [self::SEEDED_TOKEN]]);

        $result = $this->callTool('openapi.endpoints', ['path' => 'openapi/examples-in-media.json']);

        $this->assertTrue($result['isError']);
        $this->assertStringNotContainsString(self::SEEDED_TOKEN, (string) json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_drop_mode_returns_neither_the_email_nor_the_media_example(): void
    {
        config(['context-gate.sources.openapi.examples' => 'drop']);

        $result = $this->callTool('openapi.endpoints', ['path' => 'openapi/examples-in-media.json']);
        $rows = json_decode((string) $result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        $text = (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertFalse($result['isError']);
        $this->assertStringNotContainsString(self::SEEDED_EMAIL, $text);
        $this->assertStringNotContainsString(self::SEEDED_TOKEN, $text);
        $this->assertNull($rows[0]['examples']);
    }
}
