<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Server\SourceTools;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

/** FR-002 and User Story 1 scenario 1: each endpoint carries method, path, parameters, schemas and security names. */
class OpenApiEndpointsTest extends TestCase
{
    use GateCallHarness;

    private const USER = [
        'type' => 'object',
        'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']],
        'required' => ['id', 'name'],
    ];

    private const NEW_USER = [
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string'], 'email' => ['type' => 'string', 'format' => 'email']],
        'required' => ['name', 'email'],
    ];

    /** Every column of the endpoint pseudo-table is internal in the test catalog. */
    private const COLUMNS = ['version', 'method', 'path', 'parameters', 'request_schema', 'response_schemas', 'security'];

    /** Fields the firewall wraps as untrusted text. */
    private const WRAPPED_FIELDS = ['version', 'path', 'security', 'parameters', 'request_schema', 'response_schemas'];

    /** Wrapped fields whose text is a JSON document. */
    private const JSON_FIELDS = ['parameters', 'request_schema', 'response_schemas'];

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('source.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => ['openapi.endpoints']]);
        config(['context-gate.classification' => ['source_openapi_endpoints' => array_fill_keys(self::COLUMNS, 'internal')]]);
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

    public function test_each_endpoint_returns_method_path_parameters_schemas_and_security_names(): void
    {
        $rows = $this->endpointRows(['path' => 'openapi/valid-3.1.json']);

        $this->assertSame([
            [
                'version' => '3.1.0',
                'method' => 'GET',
                'path' => '/users/{id}',
                'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                'request_schema' => null,
                'response_schemas' => ['200' => self::USER],
                'security' => 'bearerAuth',
            ],
            [
                'version' => '3.1.0',
                'method' => 'POST',
                'path' => '/users',
                'parameters' => [],
                'request_schema' => self::NEW_USER,
                'response_schemas' => ['201' => self::USER],
                'security' => 'bearerAuth',
            ],
        ], $rows);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return list<array<string, mixed>> the rows with each wrapped field unwrapped, the JSON ones decoded
     */
    private function endpointRows(array $arguments): array
    {
        $result = $this->callTool('openapi.endpoints', $arguments);
        $this->assertFalse($result['isError']);

        /** @var list<array<string, mixed>> $rows */
        $rows = json_decode((string) $result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        return array_map(function (array $row): array {
            foreach (self::WRAPPED_FIELDS as $field) {
                if (is_array($row[$field] ?? null)) {
                    $text = $this->textOf($row[$field]);
                    $row[$field] = in_array($field, self::JSON_FIELDS, true)
                        ? json_decode($text, true, 512, JSON_THROW_ON_ERROR)
                        : $text;
                }
            }

            return $row;
        }, $rows);
    }

    /** @param array<string, mixed> $wrapped */
    private function textOf(array $wrapped): string
    {
        $this->assertTrue($wrapped['untrusted']);
        $this->assertSame('openapi.endpoints', $wrapped['source']);

        return (string) $wrapped['text'];
    }
}
