<?php

declare(strict_types=1);

namespace ContextGate\Source\Fetch;

/** An address in any of these ranges refuses the whole request. */
final class AddressRanges
{
    private const RANGES = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '224.0.0.0/3',
        '::/96', '64:ff9b::/96', '64:ff9b:1::/48', '2001::/32', '2001:db8::/32', '2002::/16',
        '::1/128', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    public static function refuses(string $address): bool
    {
        $packed = inet_pton($address);

        if ($packed === false) {
            return true;
        }

        // An IPv4-mapped IPv6 address is judged by its IPv4 part.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        }

        foreach (self::RANGES as $range) {
            if (self::inRange($packed, $range)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $packed, string $range): bool
    {
        [$network, $prefix] = explode('/', $range);
        $networkPacked = (string) inet_pton($network);

        if (strlen($networkPacked) !== strlen($packed)) {
            return false;
        }

        $bytes = intdiv((int) $prefix, 8);
        $bits = (int) $prefix % 8;

        if (substr($packed, 0, $bytes) !== substr($networkPacked, 0, $bytes)) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $bits)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($networkPacked[$bytes]) & $mask);
    }
}
