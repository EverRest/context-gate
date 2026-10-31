<?php

declare(strict_types=1);

namespace ContextGate\Source\Fetch;

use ContextGate\Server\ReasonCode;
use ContextGate\Source\SourceFailure;

/** FR-005, FR-006, research R2 steps 1–4: returns the one address the request may connect to. */
final class UrlGuard
{
    /** @param list<string> $allowlist */
    public function __construct(private readonly array $allowlist, private readonly Resolver $resolver)
    {
    }

    public function check(string $url): string
    {
        $parts = parse_url($url) ?: [];
        $host = strtolower($parts['host'] ?? '');
        $allowed = array_map(strtolower(...), $this->allowlist);

        // ponytail: port must be 443 so the CURLOPT_RESOLVE pin in HttpTransport covers the connection
        if (($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment'])
            || ($parts['port'] ?? 443) !== 443
            || ! in_array($host, $allowed, true)) {
            throw new SourceFailure(ReasonCode::EgressDenied);
        }

        $addresses = $this->resolver->addresses($host);

        if ($addresses === []) {
            throw new SourceFailure(ReasonCode::EgressDenied);
        }

        foreach ($addresses as $address) {
            if (AddressRanges::refuses($address)) {
                throw new SourceFailure(ReasonCode::EgressDenied);
            }
        }

        return $addresses[0];
    }
}
