<?php

declare(strict_types=1);

namespace ContextGate\Source;

use Closure;
use ContextGate\Pipeline\CanaryCheck;
use ContextGate\Pipeline\Caps;
use ContextGate\Pipeline\Normalizer;
use ContextGate\Pipeline\Payload;
use ContextGate\Pipeline\Redact;
use ContextGate\Privacy\Redactor;
use ContextGate\Server\ReasonCode;
use Throwable;

/**
 * docs.read (spec 003 US4, research R5): the path policy decides access first. The file is then read read-only up to
 * the hard cap, and the text goes through the spec 002 normalizer and redactor. Only then is it cut to the per-call
 * limit at a line boundary, so the limit holds on the redacted text. The canary check follows; quarantine is left to
 * the firewall pipeline, which wraps the text field as untrusted when the source runs as a tool.
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

        // The read stops at the hard cap; a longer file is cut at a line boundary before redaction.
        $text = (string) fread($handle, Caps::PER_CALL + 1);
        fclose($handle);

        $truncated = strlen($text) > Caps::PER_CALL;

        if ($truncated) {
            $text = self::fit($text, Caps::PER_CALL);
        }

        $identity = static fn (Payload $next): Payload => $next;
        $payload = new Payload([['text' => $text]], ['text']);

        try {
            $payload = (new Normalizer(['text']))->handle($payload, $identity);
        } catch (Throwable) {
            return SourceResult::failed(ReasonCode::PolicyDenied);
        }

        // Before redaction: a canary shaped like an email would otherwise be replaced and slip past the check.
        $payload = (new CanaryCheck($this->canaries))->handle($payload, $identity);

        if (in_array(CanaryCheck::FLAG, $payload->flags, true)) {
            return SourceResult::failed(ReasonCode::CanaryHit);
        }

        $payload = (new Redact(app(Redactor::class), ['text']))->handle($payload, $identity);

        if ($payload->dropped !== []) {
            return SourceResult::failed(ReasonCode::PolicyDenied);
        }

        // Redaction runs first, so the per-call cap applies to the text that leaves (a placeholder may be longer).
        $redacted = (string) $payload->rows[0]['text'];
        $truncated = $truncated || strlen($redacted) > $limit;
        $payload->rows[0]['text'] = self::fit($redacted, $limit);

        $payload = (new CanaryCheck($this->canaries))->handle($payload, $identity);

        if (in_array(CanaryCheck::FLAG, $payload->flags, true)) {
            return SourceResult::failed(ReasonCode::CanaryHit);
        }

        return SourceResult::ok([
            'source' => $path,
            'text' => $payload->rows[0]['text'],
            'truncated' => $truncated,
        ]);
    }

    /** Cuts the text to the limit at its last line boundary; text that fits is returned as it is. */
    private static function fit(string $text, int $limit): string
    {
        if (strlen($text) <= $limit) {
            return $text;
        }

        $window = substr($text, 0, $limit);
        $cut = strrpos($window, "\n");

        // No newline in the window: cut at the byte limit on a UTF-8 character boundary.
        return $cut === false ? mb_strcut($text, 0, $limit) : substr($window, 0, $cut + 1);
    }
}
