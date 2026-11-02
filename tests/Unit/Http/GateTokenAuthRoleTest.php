<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Http;

use ContextGate\Http\GateTokenAuth;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The role ability is read once per request. A token with two role abilities is malformed: it is refused with 403 and
 * token.ability (not 401 as two ceilings are; see the spec 010 amendments, open question on the status).
 */
class GateTokenAuthRoleTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->lines[] = $line;
        });
    }

    public function test_one_role_ability_sets_the_role_on_the_request(): void
    {
        $request = $this->requestWith(['gate:read', 'gate:ceiling:internal', 'gate:role:support']);

        $this->assertSame(200, $this->through($request)->getStatusCode());
        $this->assertSame('support', $request->attributes->get(GateTokenAuth::ROLE_ATTRIBUTE));
    }

    public function test_a_token_with_no_role_ability_authenticates_with_a_null_role(): void
    {
        $request = $this->requestWith(['gate:read', 'gate:ceiling:internal']);

        $this->assertSame(200, $this->through($request)->getStatusCode());
        $this->assertNull($request->attributes->get(GateTokenAuth::ROLE_ATTRIBUTE));
    }

    public function test_two_role_abilities_are_refused_with_token_ability(): void
    {
        $response = $this->through($this->requestWith(['gate:read', 'gate:ceiling:internal', 'gate:role:a', 'gate:role:b']));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('token.ability', $this->receipt()['reason']);
    }

    /** @return array<string, mixed> */
    private function receipt(): array
    {
        return json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $abilities */
    private function requestWith(array $abilities): Request
    {
        $new = TokenFactory::create($abilities);
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
