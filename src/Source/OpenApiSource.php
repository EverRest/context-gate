<?php

declare(strict_types=1);

namespace ContextGate\Source;

use ContextGate\Server\ReasonCode;
use ContextGate\Source\Fetch\DnsResolver;
use ContextGate\Source\Fetch\HttpTransport;
use ContextGate\Source\Fetch\PinnedClient;
use ContextGate\Source\Fetch\Resolver;
use ContextGate\Source\Fetch\Transport;
use ContextGate\Source\Fetch\UrlGuard;

/**
 * US1 (FR-002, FR-003, FR-004, FR-011) and US2 (FR-005 to FR-010): reads one OpenAPI 3.x document, from a local path
 * or from an allowlisted URL, and lists its endpoints with examples dropped unless examples is 'redacted'. Exactly one
 * of path and url is given. The firewall runs on the result when the source runs as a tool.
 */
final class OpenApiSource implements Source
{
    private readonly bool $keepExamples;

    /**
     * @param list<string> $allowedPaths allowlisted roots relative to the application root
     * @param list<string> $allowlist hosts a URL may name; empty refuses every URL (FR-005)
     * @param mixed $examples 'redacted' keeps example values for the firewall; any other value drops them (FR-003)
     */
    public function __construct(
        private readonly string $root,
        private readonly array $allowedPaths,
        private readonly array $allowlist = [],
        private readonly ?Resolver $resolver = null,
        private readonly ?Transport $transport = null,
        mixed $examples = 'drop',
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

        return $this->rows($document, $extension === 'json' ? 'json' : 'yaml');
    }

    /** US2: the guard checks the host and its addresses, the client connects to the first one and refuses redirects. */
    private function readUrl(mixed $url): SourceResult
    {
        if (! is_string($url) || $url === '') {
            return SourceResult::failed(ReasonCode::InputInvalid);
        }

        try {
            $address = (new UrlGuard($this->allowlist, $this->resolver ?? new DnsResolver()))->check($url);
            $document = (new PinnedClient($this->transport ?? new HttpTransport()))->get($url, $address);
        } catch (SourceFailure $failure) {
            return SourceResult::failed($failure->reason);
        }

        return $this->rows($document, str_starts_with(ltrim($document), '{') ? 'json' : 'yaml');
    }

    private function rows(string $document, string $format): SourceResult
    {
        try {
            $parsed = (new OpenApiParser())->parse($document, $format);
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
