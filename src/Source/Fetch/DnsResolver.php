<?php

declare(strict_types=1);

namespace ContextGate\Source\Fetch;

/** Production resolver: A and AAAA records through the system resolver. */
final class DnsResolver implements Resolver
{
    public function addresses(string $host): array
    {
        $addresses = [];

        foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip)) {
                $addresses[] = $ip;
            }
        }

        return $addresses;
    }
}
