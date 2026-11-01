<?php

declare(strict_types=1);

namespace ContextGate\Server\Sources;

use ContextGate\Pipeline\Caps;
use ContextGate\Server\Canaries;
use ContextGate\Server\SourceTools;
use ContextGate\Server\Tool;
use ContextGate\Source\DocsSource;
use ContextGate\Source\SourceFailure;
use ContextGate\Source\SourceRequest;
use Laravel\Mcp\Response;

/** docs.read (spec 003 US4): one row for the file. The firewall wraps the text as untrusted. */
final class DocsReadTool extends Tool
{
    public function name(): string
    {
        return 'docs.read';
    }

    public function description(): string
    {
        return 'Reads one documentation file from the configured allowlisted paths. Denied paths are refused.';
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

        foreach ((array) config('context-gate.sources.docs.paths', []) as $path) {
            if (is_string($path)) {
                $paths[] = $path;
            }
        }

        $limit = config('context-gate.firewall.limits.per_call_bytes');

        $result = (new DocsSource(base_path(), $paths, Canaries::values(), is_int($limit) ? $limit : Caps::PER_CALL))
            ->read(new SourceRequest($arguments));

        if (! $result->ok) {
            throw SourceFailure::from($result);
        }

        $row = [
            'source' => SourceTools::text($result->data['source'] ?? null),
            'text' => SourceTools::text($result->data['text'] ?? null),
            'truncated' => ($result->data['truncated'] ?? false) === true,
        ];

        return Response::text((string) json_encode($row, JSON_THROW_ON_ERROR));
    }
}
