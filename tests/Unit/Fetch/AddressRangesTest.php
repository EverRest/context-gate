<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Fetch;

use ContextGate\Source\Fetch\AddressRanges;
use ContextGate\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** FR-006, research R2 step 4: every resolved address is checked against the refusal ranges. */
class AddressRangesTest extends TestCase
{
    /** @return array<string, array{string, bool}> address => refused */
    public static function cases(): array
    {
        return [
            'ipv4 private 10/8' => ['10.0.0.5', true],
            'ipv4 private 172.16/12' => ['172.16.0.1', true],
            'ipv4 private 192.168/16' => ['192.168.1.1', true],
            'ipv4 loopback' => ['127.0.0.1', true],
            'ipv4 metadata link-local' => ['169.254.169.254', true],
            'ipv4 this network' => ['0.0.0.0', true],
            'ipv4 carrier-grade NAT' => ['100.64.0.1', true],
            'ipv4 multicast' => ['224.0.0.1', true],
            'ipv4 public' => ['8.8.8.8', false],
            'ipv6 loopback' => ['::1', true],
            'ipv6 unique local' => ['fc00::1', true],
            'ipv6 link-local' => ['fe80::1', true],
            'ipv6 unspecified' => ['::', true],
            'ipv4 mapped private' => ['::ffff:10.0.0.5', true],
            'ipv6 public' => ['2606:4700::1111', false],
            'ipv4 reserved 240/4' => ['240.0.0.1', true],
            'ipv4 benchmarking 198.18/15' => ['198.18.0.1', true],
            'ipv4 IETF protocol 192.0.0/24' => ['192.0.0.8', true],
            'ipv4 6to4 relay 192.88.99/24' => ['192.88.99.1', true],
            'ipv6 IPv4-compatible ::/96' => ['::10.0.0.5', true],
            'ipv6 NAT64 well-known' => ['64:ff9b::10.0.0.5', true],
            'ipv6 NAT64 local-use' => ['64:ff9b:1::1', true],
            'ipv6 Teredo 2001::/32' => ['2001:0:4136:e378:8000:63bf:3fff:fdd2', true],
            'ipv6 documentation 2001:db8' => ['2001:db8::1', true],
            'ipv6 6to4 2002::/16' => ['2002:0a00:0005::1', true],
            'ipv6 site-local fec0::/10' => ['fec0::1', true],
            'ipv6 multicast ff00::/8' => ['ff02::1', true],
        ];
    }

    #[DataProvider('cases')]
    public function test_refuses_reserved_addresses(string $address, bool $refused): void
    {
        $this->assertSame($refused, AddressRanges::refuses($address));
    }
}
