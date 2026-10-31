<?php

declare(strict_types=1);

namespace ContextGate\Source\Fetch;

use ContextGate\Server\ReasonCode;
use ContextGate\Source\SourceFailure;

/** FR-008 to FR-010, research R2 steps 6–8: redirects, timeout, size cap and content type. */
final class PinnedClient
{
    private const TIMEOUT_SECONDS = 5.0;

    private const MAX_BYTES = 2 * 1024 * 1024;

    private const CONTENT_TYPES = [
        'application/json', 'application/yaml', 'application/x-yaml', 'text/yaml', 'text/x-yaml',
    ];

    public function __construct(private readonly Transport $transport)
    {
    }

    public function get(string $url, string $address): string
    {
        try {
            $reply = $this->transport->fetch($url, $address, self::TIMEOUT_SECONDS);
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

        $body = '';

        foreach ($reply->chunks as $chunk) {
            $body .= $chunk;

            if (strlen($body) > self::MAX_BYTES) {
                throw new SourceFailure(ReasonCode::SourceTooLarge);
            }
        }

        return $body;
    }
}
