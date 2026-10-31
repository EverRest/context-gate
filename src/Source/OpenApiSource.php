<?php

declare(strict_types=1);

namespace ContextGate\Source;

use ContextGate\Server\ReasonCode;

/**
 * US1 (FR-002, FR-003, FR-004, FR-011): reads one local OpenAPI 3.x document and lists its endpoints with examples
 * dropped. The path policy decides access first; a URL is never requested here. The firewall runs on the result
 * when the source runs as a tool.
 */
final class OpenApiSource implements Source
{
    /** @param list<string> $allowedPaths allowlisted roots relative to the application root */
    public function __construct(
        private readonly string $root,
        private readonly array $allowedPaths,
    ) {
    }

    public function name(): string
    {
        return 'openapi.endpoints';
    }

    public function read(SourceRequest $request): SourceResult
    {
        $path = $request->arguments['path'] ?? null;

        if (! is_string($path) || $path === '') {
            return SourceResult::failed(ReasonCode::InputInvalid);
        }

        $step = (new DocsPathPolicy($this->root, $this->allowedPaths))->refusalStep($path);

        if ($step !== null) {
            return SourceResult::refused($step);
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, ['json', 'yaml', 'yml'], true)) {
            return SourceResult::refused('extension');
        }

        $real = realpath($this->root . '/' . ltrim($path, '/'));

        // The policy resolved the path a moment ago; a symlink swapped since then must not lead out of the root.
        if ($real === false || ! str_starts_with($real, (string) realpath($this->root) . '/')) {
            return SourceResult::refused('root');
        }

        $handle = fopen($real, 'rb');

        if ($handle === false) {
            return SourceResult::failed(ReasonCode::SourceMissing);
        }

        // The read stops one byte past the parser cap, so an oversized file is refused without being loaded whole.
        $document = (string) fread($handle, OpenApiParser::MAX_BYTES + 1);
        fclose($handle);

        $parser = new OpenApiParser();

        try {
            $parsed = $parser->parse($document, $extension === 'json' ? 'json' : 'yaml');
        } catch (SourceFailure $failure) {
            return SourceResult::failed($failure->reason);
        }

        $parsed = (new ExamplePolicy())->drop($parsed);

        return SourceResult::ok([
            'version' => (string) ($parsed['openapi'] ?? ''),
            'endpoints' => (new OpenApiEndpoints($parsed, strlen($document)))->rows(),
        ]);
    }
}
