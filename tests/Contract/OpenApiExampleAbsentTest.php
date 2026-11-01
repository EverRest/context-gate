<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Server\SourceTools;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

/** User Story 1 scenario 2 and SC-001: the seeded email of the example is absent from the response by default. */
class OpenApiExampleAbsentTest extends TestCase
{
    use GateCallHarness;

    private const SEEDED_EMAIL = 'canary-fixture@example.invalid';

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

    public function test_the_seeded_email_from_the_example_appears_nowhere_in_the_response(): void
    {
        $result = $this->callTool('openapi.endpoints', ['path' => 'openapi/valid-3.1.json']);

        $this->assertFalse($result['isError']);
        $this->assertStringNotContainsString(self::SEEDED_EMAIL, (string) json_encode($result));
        $this->assertStringNotContainsString('Fixture Person', (string) json_encode($result));
    }

    public function test_the_schema_that_names_the_email_property_is_still_returned(): void
    {
        $result = $this->callTool('openapi.endpoints', ['path' => 'openapi/valid-3.1.json']);
        $rows = json_decode((string) $result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(2, $rows);
        $this->assertStringContainsString('"format":"email"', (string) $rows[1]['request_schema']['text']);
    }
}
