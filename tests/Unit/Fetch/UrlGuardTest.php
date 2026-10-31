<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Fetch;

use ContextGate\Server\ReasonCode;
use ContextGate\Source\Fetch\UrlGuard;
use ContextGate\Source\SourceFailure;
use ContextGate\Tests\Fakes\FakeResolver;
use ContextGate\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** FR-005, FR-006, research R2 steps 1–4. */
class UrlGuardTest extends TestCase
{
    /** @return array<string, array{string, list<string>}> url => allowlist */
    public static function refusedUrls(): array
    {
        return [
            'non-https' => ['http://api.example.test/openapi.json', ['api.example.test']],
            'userinfo' => ['https://user@api.example.test/openapi.json', ['api.example.test']],
            'fragment' => ['https://api.example.test/openapi.json#top', ['api.example.test']],
            'host not on allowlist' => ['https://other.example.test/openapi.json', ['api.example.test']],
            'empty allowlist' => ['https://api.example.test/openapi.json', []],
        ];
    }

    /** @param list<string> $allowlist */
    #[DataProvider('refusedUrls')]
    public function test_refuses_url_before_any_lookup(string $url, array $allowlist): void
    {
        $resolver = new FakeResolver([['203.0.113.10']]);

        $this->expectException(SourceFailure::class);

        try {
            (new UrlGuard($allowlist, $resolver))->check($url);
        } finally {
            $this->assertSame(0, $resolver->lookups);
        }
    }

    public function test_allowlisted_host_resolving_to_a_refused_address_is_refused_whole(): void
    {
        $resolver = new FakeResolver([['203.0.113.10', '10.0.0.5']]);

        try {
            (new UrlGuard(['api.example.test'], $resolver))->check('https://api.example.test/openapi.json');
            $this->fail('A refused address must refuse the whole request');
        } catch (SourceFailure $refused) {
            $this->assertSame(ReasonCode::EgressDenied, $refused->reason);
        }
    }

    public function test_allowlisted_public_host_returns_its_connect_address(): void
    {
        $resolver = new FakeResolver([['203.0.113.10']]);

        $address = (new UrlGuard(['api.example.test'], $resolver))->check('https://api.example.test/openapi.json');

        $this->assertSame('203.0.113.10', $address);
    }
}
