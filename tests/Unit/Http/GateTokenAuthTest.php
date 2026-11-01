<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Http;

use ContextGate\Http\GateTokenAuth;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use ContextGate\Token\Ceiling;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use ContextGate\Token\GateAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

class GateTokenAuthTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    private int $tokenId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->lines[] = $line;
        });
    }

    public function test_exactly_one_ceiling_ability_sets_the_ceiling_on_the_request(): void
    {
        $request = $this->requestWith(['gate:read', 'gate:ceiling:internal']);

        $response = $this->through($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(Ceiling::Internal, $request->attributes->get(GateTokenAuth::CEILING_ATTRIBUTE));
    }

    public function test_no_ceiling_ability_is_refused(): void
    {
        $response = $this->through($this->requestWith(['gate:read']));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('token.no_ceiling', $this->receipt()['reason']);
    }

    public function test_two_ceiling_abilities_are_refused(): void
    {
        $response = $this->through($this->requestWith(['gate:read', 'gate:ceiling:public', 'gate:ceiling:internal']));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('token.no_ceiling', $this->receipt()['reason']);
    }

    public function test_secret_ceiling_ability_is_refused(): void
    {
        $response = $this->through($this->requestWith(['gate:read', 'gate:ceiling:secret']));

        $this->assertSame(403, $response->getStatusCode());
    }

    #[DataProvider('abilitiesOutsideThePackageSet')]
    public function test_token_with_an_ability_outside_the_package_set_is_refused(string $ability): void
    {
        $request = $this->requestWith(['gate:read', 'gate:ceiling:personal', $ability]);

        $this->assertSame(403, $this->through($request)->getStatusCode());
        $this->assertSame('token.ability', $this->receipt()['reason']);
        $this->assertNull($request->attributes->get(GateTokenAuth::CEILING_ATTRIBUTE));
    }

    /** @return iterable<string, array{string}> */
    public static function abilitiesOutsideThePackageSet(): iterable
    {
        yield 'wildcard' => ['*'];
        yield 'application ability' => ['orders:write'];
    }

    public function test_non_string_ability_is_refused_not_a_server_error(): void
    {
        $token = new GateAccessToken();
        $token->forceFill(['id' => 99, 'name' => 'Ana', 'abilities' => ['gate:read', 'gate:ceiling:internal', 5]]);

        $user = TokenFactory::user();
        $user->withAccessToken($token);

        $request = Request::create('/mcp/context-gate', 'POST');
        $request->setUserResolver(static fn (): object => $user);

        $this->assertSame(403, $this->through($request)->getStatusCode());
        $this->assertSame('token.ability', $this->receipt()['reason']);
    }

    public function test_receipt_caller_is_the_token_id_not_the_holder_name(): void
    {
        $this->through($this->requestWith(['gate:read']));

        $this->assertSame('token:'.$this->tokenId, $this->receipt()['caller']);
    }

    public function test_refusal_body_names_no_reason(): void
    {
        $response = $this->through($this->requestWith(['gate:read']));

        $this->assertStringNotContainsString('ceiling', (string) $response->getContent());
    }

    public function test_ceiling_comes_from_the_token_only(): void
    {
        $request = $this->requestWith(['gate:read', 'gate:ceiling:internal']);
        $request->merge(['ceiling' => 'personal']);
        $request->headers->set('X-Ceiling', 'personal');

        $this->through($request);

        $this->assertSame(Ceiling::Internal, $request->attributes->get(GateTokenAuth::CEILING_ATTRIBUTE));
    }

    /** @return array<string, mixed> */
    private function receipt(): array
    {
        return json_decode($this->lines[0], true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $abilities */
    private function requestWith(array $abilities): Request
    {
        $new = TokenFactory::create($abilities);
        $this->tokenId = (int) $new->accessToken->getKey();

        $user = TokenFactory::user();
        $user->withAccessToken($new->accessToken);

        $request = Request::create('/mcp/context-gate', 'POST');
        $request->setUserResolver(static fn (): object => $user);

        return $request;
    }

    private function through(Request $request): Response
    {
        return (new GateTokenAuth())->handle($request, static fn (): Response => new Response('', 200));
    }
}
