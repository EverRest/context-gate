<?php

declare(strict_types=1);

namespace ContextGate\Server\Sources;

use ContextGate\Server\SourceTools;
use ContextGate\Server\Tool;
use ContextGate\Source\ConfigSource;
use ContextGate\Source\SourceFailure;
use ContextGate\Source\SourceRequest;
use Laravel\Mcp\Response;

/** config.keys (spec 003 US3): one row per key with its type and env name when safe. No value. */
final class ConfigKeysTool extends Tool
{
    public function name(): string
    {
        return 'config.keys';
    }

    public function description(): string
    {
        return 'Lists configuration keys with their type and, when mapped and safe, the environment variable name. '
            .'Values are never returned. Optionally narrow the list to one config section with a dotted prefix.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['prefix' => [
                'type' => 'string',
                'description' => 'Optional. Returns only the key equal to this prefix and keys below it, matched on whole dotted segments '
                    .'("app" matches "app.name" but not "application.x"). Omit or leave empty to list every key.',
                'examples' => ['database.connections'],
            ]],
        ];
    }

    public function handle(array $arguments): Response
    {
        $result = (new ConfigSource())->read(new SourceRequest($arguments));

        if (! $result->ok) {
            throw SourceFailure::from($result);
        }

        $rows = [];

        foreach (SourceTools::items($result->data['keys'] ?? null) as $key) {
            $rows[] = [
                'key' => SourceTools::text($key['key'] ?? null),
                'type' => SourceTools::text($key['type'] ?? null),
                'env' => SourceTools::nullable($key['env'] ?? null),
            ];
        }

        return Response::text((string) json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
