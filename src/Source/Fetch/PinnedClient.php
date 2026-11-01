<?php

declare(strict_types=1);

namespace ContextGate\Source\Fetch;

use ContextGate\Pipeline\Caps;
use ContextGate\Server\ReasonCode;
use ContextGate\Source\SourceFailure;

/** FR-008 to FR-010, research R2 steps 6–8: redirects, timeout, size cap and content type. */
final class PinnedClient
{
    private readonly float $timeoutSeconds;

    private readonly int $maxBytes;

    private const CONTENT_TYPES = [
        'application/json', 'application/yaml', 'application/x-yaml', 'text/yaml', 'text/x-yaml',
    ];

    /** spec 007 T030: the effective timeout and size from the active profile, clamped to their caps. */
    public function __construct(private readonly Transport $transport, float $timeoutSeconds = Caps::OPENAPI_TIMEOUT, int $maxBytes = Caps::OPENAPI_BYTES)
    {
        $this->timeoutSeconds = min($timeoutSeconds, Caps::OPENAPI_TIMEOUT);
        $this->maxBytes = min($maxBytes, Caps::OPENAPI_BYTES);
    }

    public function get(string $url, string $address): string
    {
        try {
            $reply = $this->transport->fetch($url, $address, $this->timeoutSeconds, $this->maxBytes);
        } catch (TransportTimeout) {
            throw new SourceFailure(ReasonCode::SourceTimeout);
        }

        if ($reply->status >= 300 && $reply->status < 400) {
            throw new SourceFailure(ReasonCode::SourceRedirect);
        }

        $type = strtolower(trim(explode(';', $reply->contentType)[0]));

        if ($reply->status !== 200 || ! in_array($type, self::CONTENT_TYPES, true)) {
            throw new SourceFailure(ReasonCode::SourceRefused);
        }

        if (strlen($reply->body) > $this->maxBytes) {
            throw new SourceFailure(ReasonCode::SourceTooLarge);
        }

        return $reply->body;
    }
}
