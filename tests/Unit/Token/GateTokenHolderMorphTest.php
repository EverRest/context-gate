<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Token;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use ContextGate\Token\Ceiling;
use ContextGate\Token\GateAccessToken;
use ContextGate\Token\GateTokenHolder;
use ContextGate\Token\TokenIssuer;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

/** A host application that requires a morph map must still get gate tokens owned by GateTokenHolder. */
class GateTokenHolderMorphTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        Relation::requireMorphMap();
    }

    protected function tearDown(): void
    {
        Relation::requireMorphMap(false);

        parent::tearDown();
    }

    public function test_issuance_succeeds_under_a_required_morph_map(): void
    {
        $new = TokenIssuer::issue('Ana', 'ops-team', Ceiling::Internal, Carbon::now()->addDays(30));

        $this->assertDatabaseCount('gate_access_tokens', 1);
        $this->assertNotSame('', $new->plainTextToken);
    }

    public function test_token_tokenable_type_is_the_holder_class_under_a_required_morph_map(): void
    {
        TokenIssuer::issue('Ana', 'ops-team', Ceiling::Internal, Carbon::now()->addDays(30));

        $this->assertDatabaseHas('gate_access_tokens', ['tokenable_type' => GateTokenHolder::class]);
    }

    public function test_token_resolves_its_holder_under_a_required_morph_map(): void
    {
        $new = TokenIssuer::issue('Ana', 'ops-team', Ceiling::Internal, Carbon::now()->addDays(30));

        $token = GateAccessToken::query()->findOrFail((int) $new->accessToken->getKey());

        $this->assertInstanceOf(GateTokenHolder::class, $token->tokenable()->getResults());
    }
}
