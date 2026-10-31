<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Http;

use ContextGate\Http\GateOriginCheck;
use ContextGate\Http\GateTokenAuth;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/** The gate's middlewares accept only the gate's own token model (GateAccessToken), never Sanctum's base class. */
class GateTokenPlainModelTest extends TestCase
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

    public function test_plain_personal_access_token_is_refused_by_token_auth_as_invalid(): void
    {
        $response = (new GateTokenAuth())->handle($this->requestAs($this->plainToken()), static fn (): Response => new Response('', 200));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('token.invalid', $this->lastReason());
    }

    public function test_plain_personal_access_token_is_not_named_as_the_caller_on_origin_refusal(): void
    {
        $request = $this->requestAs($this->plainToken());
        $request->headers->set('Origin', 'https://evil.example.com');

        $response = (new GateOriginCheck())->handle($request, static fn (): Response => new Response('', 200));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('unknown', json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR)['caller']);
    }

    private function plainToken(): PersonalAccessToken
    {
        $token = new PersonalAccessToken();
        $token->forceFill([
            'id' => 7,
            'name' => 'Host',
            'abilities' => ['gate:read', 'gate:ceiling:internal'],
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        return $token;
    }

    private function requestAs(PersonalAccessToken $token): Request
    {
        $user = TokenFactory::user();
        $user->withAccessToken($token);

        $request = Request::create('/mcp/context-gate', 'POST');
        $request->setUserResolver(static fn (): object => $user);

        return $request;
    }

    private function lastReason(): string
    {
        return (string) json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR)['reason'];
    }
}
