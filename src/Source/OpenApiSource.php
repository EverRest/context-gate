<?php

declare(strict_types=1);

namespace ContextGate\Source;

use ContextGate\Pipeline\Caps;
use ContextGate\Server\ReasonCode;
use ContextGate\Source\Fetch\PinnedClient;
use ContextGate\Source\Fetch\Resolver;
use ContextGate\Source\Fetch\Transport;
use ContextGate\Source\Fetch\UrlGuard;

/**
 * Reads one OpenAPI 3.x document, from a local path
 * or from an allowlisted URL, and lists its endpoints with examples dropped unless examples is 'redacted'. Exactly one
 * of path and url is given. The firewall runs on the result when the source runs as a tool.
 */
final class OpenApiSource implements Source
{
    private readonly bool $keepExamples;

    /**
     * @param list<string> $allowedPaths allowlisted roots relative to the application root
     * @param list<string> $allowlist hosts a URL may name; empty refuses every URL
     * @param mixed $examples 'redacted' keeps example values for the firewall; any other value drops them
     * @param float $timeout effective URL fetch timeout in seconds
     * @param int $maxBytes effective document size in bytes, for the file read and the URL fetch
     */
    public function __construct(
        private readonly string $root,
        private readonly array $allowedPaths,
        private readonly array $allowlist = [],
        private readonly ?Resolver $resolver = null,
        private readonly ?Transport $transport = null,
        mixed $examples = 'drop',
        private readonly float $timeout = Caps::OPENAPI_TIMEOUT,
        private readonly int $maxBytes = Caps::OPENAPI_BYTES,
    ) {
        $this->keepExamples = $examples === 'redacted';
    }

    public function name(): string
    {
        return 'openapi.endpoints';
    }

    public function read(SourceRequest $request): SourceResult
    {
        $path = $request->arguments['path'] ?? null;
        $url = $request->arguments['url'] ?? null;

        if (($path === null) === ($url === null)) {
            return SourceResult::failed(ReasonCode::InputInvalid);
        }

        if ($url !== null) {
            return $this->readUrl($url);
        }

        if (! is_string($path) || $path === '') {
            return SourceResult::failed(ReasonCode::InputInvalid);
        }

        $policy = new DocsPathPolicy($this->root, $this->allowedPaths);
        $step = $policy->refusalStep($path);

        if ($step !== null) {
            return SourceResult::refused($step);
        }

        $real = realpath($this->root . '/' . ltrim($path, '/'));

        // The policy resolved the path a moment ago; a symlink swapped since then must pass every step again.
        if ($real === false) {
            return SourceResult::refused('root');
        }

        if (($step = $policy->resolvedStep($real)) !== null) {
            return SourceResult::refused($step);
        }

        // The file's own extension picks the parser: a symlink named spec.yaml may point at notes.md.
        $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));

        if (! in_array($extension, ['json', 'yaml', 'yml'], true)) {
            return SourceResult::refused('extension');
        }

        $handle = fopen($real, 'rb');

        if ($handle === false) {
            return SourceResult::failed(ReasonCode::SourceMissing);
        }

        // The read stops one byte past the parser cap, so an oversized file is refused without being loaded whole.
        $document = (string) fread($handle, max(1, $this->maxBytes + 1));
        fclose($handle);

        return $this->rows($document, $extension === 'json' ? 'json' : 'yaml');
    }

    /** The guard checks the host and its addresses, the client connects to the first one and refuses redirects. */
    private function readUrl(mixed $url): SourceResult
    {
        if (! is_string($url) || $url === '') {
            return SourceResult::failed(ReasonCode::InputInvalid);
        }

        try {
            $address = (new UrlGuard($this->allowlist, $this->resolver ?? app(Resolver::class)))->check($url);
            $document = (new PinnedClient($this->transport ?? app(Transport::class), $this->timeout, $this->maxBytes))->get($url, $address);
        } catch (SourceFailure $failure) {
            return SourceResult::failed($failure->reason);
        }

        return $this->rows($document, str_starts_with(ltrim($document), '{') ? 'json' : 'yaml');
    }

    private function rows(string $document, string $format): SourceResult
    {
        try {
            $parsed = (new OpenApiParser($this->maxBytes))->parse($document, $format);
        } catch (SourceFailure $failure) {
            return SourceResult::failed($failure->reason);
        }

        if (! $this->keepExamples) {
            $parsed = (new ExamplePolicy())->drop($parsed);
        }

        try {
            $endpoints = (new OpenApiEndpoints($parsed, strlen($document), $this->keepExamples))->rows();
        } catch (SourceFailure $failure) {
            return SourceResult::failed($failure->reason);
        }

        return SourceResult::ok(['version' => (string) ($parsed['openapi'] ?? ''), 'endpoints' => $endpoints]);
    }
}
