<?php

declare(strict_types=1);

namespace ContextGate\Server\Sources;

use ContextGate\Server\SourceTools;
use ContextGate\Server\Tool;
use ContextGate\Source\Fetch\Resolver;
use ContextGate\Source\Fetch\Transport;
use ContextGate\Source\OpenApiSource;
use ContextGate\Source\SourceFailure;
use ContextGate\Source\SourceRequest;
use Laravel\Mcp\Response;

/** openapi.endpoints (US1, US2): one row per endpoint. Schema-bearing fields are JSON text; the firewall wraps them as untrusted. */
final class OpenApiEndpointsTool extends Tool
{
    /** The resolver and transport are seams for tests; null uses the real network path. */
    public function __construct(private readonly ?Resolver $resolver = null, private readonly ?Transport $transport = null)
    {
    }

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
            'properties' => ['path' => ['type' => 'string'], 'url' => ['type' => 'string']],
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

        $allowlist = array_values(array_filter((array) config('context-gate.sources.openapi.allowlist', []), 'is_string'));
        $source = new OpenApiSource(base_path(), $paths, $allowlist, $this->resolver, $this->transport);
        $result = $source->read(new SourceRequest($arguments));

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
        // Unescaped, so the canary check and the redaction see the text as written, not as \\u00e9 or \\/ sequences.
        return (string) json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
