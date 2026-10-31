<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Http;

use ContextGate\Http\GateOriginCheck;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

class GateOriginCheckTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        config(['context-gate.http.origins' => ['https://internal-tools.example.com']]);

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->lines[] = $line;
        });
    }

    public function test_request_without_origin_passes_even_with_an_empty_allowlist(): void
    {
        config(['context-gate.http.origins' => []]);

        $this->assertSame(200, $this->through()->getStatusCode());
    }

    public function test_any_origin_is_refused_with_an_empty_allowlist(): void
    {
        config(['context-gate.http.origins' => []]);

        $response = $this->through('https://internal-tools.example.com');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
    }

    public function test_exact_allowlisted_origin_passes(): void
    {
        $this->assertSame(200, $this->through('https://internal-tools.example.com')->getStatusCode());
    }

    public function test_differing_port_is_refused(): void
    {
        $this->assertSame(403, $this->through('https://internal-tools.example.com:8443')->getStatusCode());
    }

    public function test_differing_scheme_is_refused(): void
    {
        $this->assertSame(403, $this->through('http://internal-tools.example.com')->getStatusCode());
    }

    public function test_null_origin_is_refused(): void
    {
        config(['context-gate.http.origins' => ['null']]);

        $this->assertSame(403, $this->through('null')->getStatusCode());
    }

    #[DataProvider('nonMatchingOrigins')]
    public function test_origin_that_is_not_an_exact_match_is_refused(string $origin): void
    {
        $this->assertSame(403, $this->through($origin)->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function nonMatchingOrigins(): iterable
    {
        yield 'empty string' => [''];
        yield 'trailing slash' => ['https://internal-tools.example.com/'];
        yield 'upper-case host' => ['https://Internal-Tools.example.com'];
    }

    public function test_empty_origin_is_refused_and_receipted_even_when_the_allowlist_contains_an_empty_string(): void
    {
        config(['context-gate.http.origins' => ['']]);

        $this->assertSame(403, $this->through('')->getStatusCode());
        $this->assertCount(1, $this->lines);
        $this->assertSame('origin.denied', json_decode($this->lines[0], true)['reason']);
    }

    public function test_two_origin_headers_are_refused_even_when_the_first_is_allowlisted(): void
    {
        $response = $this->through('https://internal-tools.example.com', 'https://evil.example.com');

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_refusal_writes_a_receipt_with_the_origin_denied_reason(): void
    {
        $this->through('https://evil.example.com');

        $this->assertCount(1, $this->lines);
        $this->assertSame('origin.denied', json_decode($this->lines[0], true)['reason']);
    }

    public function test_receipt_caller_is_the_token_id_when_a_token_is_present(): void
    {
        $user = TokenFactory::user();
        $new = TokenFactory::create(['gate:read', 'gate:ceiling:internal']);
        $user->withAccessToken($new->accessToken);

        $request = $this->request('https://evil.example.com');
        $request->setUserResolver(static fn (): object => $user);

        $this->assertSame(403, $this->handle($request)->getStatusCode());
        $this->assertSame('token:'.$new->accessToken->getKey(), json_decode($this->lines[0], true)['caller']);
    }

    private function through(string ...$origins): Response
    {
        return $this->handle($this->request(...$origins));
    }

    private function request(string ...$origins): Request
    {
        $request = Request::create('/mcp/context-gate', 'POST');

        foreach ($origins as $index => $origin) {
            $request->headers->set('Origin', $origin, $index === 0);
        }

        return $request;
    }

    private function handle(Request $request): Response
    {
        return (new GateOriginCheck())->handle($request, static fn (): Response => new Response('', 200));
    }
}
