<?php

declare(strict_types=1);

namespace ContextGate\Server\Sources;

use ContextGate\Server\SourceTools;
use ContextGate\Settings\EffectiveSettings;
use ContextGate\Server\Tool;
use ContextGate\Source\Fetch\Resolver;
use ContextGate\Source\Fetch\Transport;
use ContextGate\Source\OpenApiSource;
use ContextGate\Source\SourceFailure;
use ContextGate\Source\SourceRequest;
use Laravel\Mcp\Response;

/** openapi.endpoints: one row per endpoint. Schema-bearing fields are JSON text; the firewall wraps them as untrusted. */
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
            . 'request and response schemas, and security names. Example values are removed unless the operator keeps them '
            . 'for redaction; external references are not followed.';
    }

    /** A url argument reaches the network, so the tool is open-world; it is still read-only. */
    public function annotations(): array
    {
        return ['openWorldHint' => true] + parent::annotations();
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Local .json, .yaml or .yml file relative to the application root, inside an allowlisted path. '
                        .'Give exactly one of path and url.',
                    'examples' => ['docs/openapi.yaml'],
                ],
                'url' => [
                    'type' => 'string',
                    'description' => 'URL of an OpenAPI document on a host the operator allowlisted; any other host is refused. '
                        .'Give exactly one of path and url.',
                    'examples' => ['https://api.example.com/openapi.json'],
                ],
            ],
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
        $effective = EffectiveSettings::current();
        $source = new OpenApiSource(base_path(), $paths, $allowlist, $this->resolver, $this->transport, EffectiveSettings::examples() ?? 'drop', (float) $effective['sources.openapi.timeout_seconds'], (int) $effective['sources.openapi.max_bytes']);
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
                'examples' => ($endpoint['examples'] ?? null) === null ? null : self::json($endpoint['examples']),
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
