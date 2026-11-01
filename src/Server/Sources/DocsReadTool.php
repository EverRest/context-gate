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
use ContextGate\Settings\EffectiveSettings;
use Laravel\Mcp\Response;

/** docs.read: one row for the file. The firewall wraps the text as untrusted. */
final class DocsReadTool extends Tool
{
    /** Room for the untrusted wrapper, the truncated flag and the row framing the firewall adds. */
    private const WRAPPER_BYTES = 256;

    public function name(): string
    {
        return 'docs.read';
    }

    public function description(): string
    {
        return 'Reads one documentation file from the operator-configured allowlisted paths. Paths outside the allowlist, '
            .'secret-like files and anything outside the application root are refused. Long files are cut at a line boundary '
            .'and the result says truncated: true.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['path' => [
                'type' => 'string',
                'description' => 'File path relative to the application root (not the docs folder), inside an allowlisted docs path.',
                'examples' => ['docs/guide.md'],
            ]],
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

        // The effective profile value (strict is lower than the cap), so the firewall does not drop a row this tool kept.
        $limit = (int) EffectiveSettings::current()['firewall.limits.per_call_bytes'];

        $result = (new DocsSource(base_path(), $paths, Canaries::values(), min($limit, Caps::PER_CALL)))
            ->read(new SourceRequest($arguments));

        if (! $result->ok) {
            throw SourceFailure::from($result);
        }

        $source = SourceTools::text($result->data['source'] ?? null);
        $text = SourceTools::text($result->data['text'] ?? null);
        $truncated = ($result->data['truncated'] ?? false) === true;

        // The firewall budget measures the JSON-encoded row (newlines and non-ASCII grow), not the raw text.
        while (strlen((string) json_encode(['source' => $source, 'text' => $text], JSON_THROW_ON_ERROR)) + self::WRAPPER_BYTES > $limit && $text !== '') {
            $text = self::cut($text);
            $truncated = true;
        }

        $row = ['source' => $source, 'text' => $text, 'truncated' => $truncated];

        return Response::text((string) json_encode($row, JSON_THROW_ON_ERROR));
    }

    /** Drops about a tenth of the text, at a line boundary when there is one. */
    private static function cut(string $text): string
    {
        $window = mb_strcut($text, 0, (int) (strlen($text) * 0.9));
        $line = strrpos($window, "\n");

        return $line === false ? $window : substr($window, 0, $line + 1);
    }
}
