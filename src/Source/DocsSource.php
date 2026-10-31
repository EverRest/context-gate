<?php

declare(strict_types=1);

namespace ContextGate\Source;

use Closure;
use ContextGate\Pipeline\CanaryCheck;
use ContextGate\Pipeline\Caps;
use ContextGate\Pipeline\Normalizer;
use ContextGate\Pipeline\Payload;
use ContextGate\Pipeline\Quarantine;
use ContextGate\Pipeline\Redact;
use ContextGate\Privacy\Redactor;
use ContextGate\Server\ReasonCode;
use Throwable;

/**
 * docs.read (spec 003 US4, research R5): the path policy decides access first. The file is then read read-only up to
 * the per-call limit, cut at a line boundary, and the text goes through the spec 002 normalizer, redactor, canary
 * check and quarantine.
 */
final class DocsSource implements Source
{
    /**
     * @param list<string> $allowedPaths allowlisted roots relative to the application root
     * @param list<string> $canaries configured canary values
     * @param (Closure(): void)|null $beforeRead test seam: runs after the policy and before the file is resolved
     */
    public function __construct(
        private readonly string $root,
        private readonly array $allowedPaths,
        private readonly array $canaries,
        private readonly int $perCall,
        private readonly ?Closure $beforeRead = null,
    ) {
    }

    public function name(): string
    {
        return 'docs.read';
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

        if ($this->beforeRead !== null) {
            ($this->beforeRead)();
        }

        $real = realpath($this->root . '/' . ltrim($path, '/'));

        if ($real === false) {
            return SourceResult::failed(ReasonCode::SourceMissing);
        }

        // The policy resolved the path a moment ago; a symlink swapped since then must not lead out of the root.
        if (! str_starts_with($real, (string) realpath($this->root) . '/')) {
            return SourceResult::refused('root');
        }

        $handle = fopen($real, 'rb');

        if ($handle === false) {
            return SourceResult::failed(ReasonCode::SourceMissing);
        }

        $limit = max(1, min($this->perCall, Caps::PER_CALL));
        $text = (string) fread($handle, $limit + 1);
        fclose($handle);

        $truncated = strlen($text) > $limit;

        if ($truncated) {
            $text = substr($text, 0, $limit);
            $cut = strrpos($text, "\n");
            $text = $cut === false ? '' : substr($text, 0, $cut + 1);
        }

        $identity = static fn (Payload $next): Payload => $next;
        $payload = new Payload([['text' => $text]], ['text']);

        try {
            $payload = (new Normalizer(['text']))->handle($payload, $identity);
        } catch (Throwable) {
            return SourceResult::failed(ReasonCode::PolicyDenied);
        }

        $payload = (new Redact(new Redactor(), ['text']))->handle($payload, $identity);

        if ($payload->dropped !== []) {
            return SourceResult::failed(ReasonCode::PolicyDenied);
        }

        $payload = (new CanaryCheck($this->canaries))->handle($payload, $identity);

        if (in_array(CanaryCheck::FLAG, $payload->flags, true)) {
            return SourceResult::failed(ReasonCode::CanaryHit);
        }

        $payload = (new Quarantine($path, ['text']))->handle($payload, $identity);

        return SourceResult::ok([
            'source' => $path,
            'text' => $payload->rows[0]['text'],
            'truncated' => $truncated,
        ]);
    }
}
