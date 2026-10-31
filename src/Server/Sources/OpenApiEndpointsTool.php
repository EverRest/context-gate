<?php

declare(strict_types=1);

namespace ContextGate\Server\Sources;

use ContextGate\Server\SourceTools;
use ContextGate\Server\Tool;
use ContextGate\Source\OpenApiSource;
use ContextGate\Source\SourceFailure;
use ContextGate\Source\SourceRequest;
use Laravel\Mcp\Response;

/** openapi.endpoints (US1): one row per endpoint. Schema-bearing fields are JSON text; the firewall wraps them as untrusted. */
final class OpenApiEndpointsTool extends Tool
{
    public function name(): string
    {
        return 'openapi.endpoints';
    }

    public function description(): string
    {
        return 'Lists the endpoints of one OpenAPI 3.x document from the configured paths: method, path, parameters, '
            . 'request and response schemas, and security names. Examples are removed; external references are not followed.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string']],
            'required' => ['path'],
        ];
    }

    public function handle(array $arguments): Response
    {
        $paths = [];

        foreach ((array) config('context-gate.sources.openapi.paths', []) as $path) {
            if (is_string($path)) {
                $paths[] = $path;
            }
        }

        $result = (new OpenApiSource(base_path(), $paths))->read(new SourceRequest($arguments));

        if (! $result->ok) {
            throw SourceFailure::from($result);
        }

        $version = SourceTools::text($result->data['version'] ?? null);
        $rows = [];

        foreach (SourceTools::items($result->data['endpoints'] ?? null) as $endpoint) {
            $rows[] = [
                'version' => $version,
                'method' => SourceTools::text($endpoint['method'] ?? null),
                'path' => SourceTools::text($endpoint['path'] ?? null),
                'parameters' => self::json($endpoint['parameters'] ?? []),
                'request_schema' => ($endpoint['request_schema'] ?? null) === null ? null : self::json($endpoint['request_schema']),
                'response_schemas' => self::json($endpoint['response_schemas'] ?? []),
                'security' => SourceTools::text($endpoint['security'] ?? null),
            ];
        }

        return Response::text((string) json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_THROW_ON_ERROR);
    }
}
