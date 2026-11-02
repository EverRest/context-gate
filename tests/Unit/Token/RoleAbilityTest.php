<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Token;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use ContextGate\Token\Ability;
use ContextGate\Token\Ceiling;
use ContextGate\Token\GateAccessToken;
use ContextGate\Token\TokenIssuer;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;

/** spec 010 FR-006, FR-007, research R2: the role is one Sanctum ability, gate:role:<name>, written once at issuance. */
class RoleAbilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
    }

    /** @return iterable<string, array{string}> */
    public static function accepted(): iterable
    {
        yield 'a plain name' => ['gate:role:support'];
        yield 'letters digits underscore dash' => ['gate:role:Help_desk-2'];
        yield 'the longest name' => ['gate:role:'.str_repeat('r', 64)];
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'an empty name' => ['gate:role:'];
        yield 'a space' => ['gate:role:help desk'];
        yield 'a dot' => ['gate:role:help.desk'];
        yield 'a colon' => ['gate:role:help:desk'];
        yield 'a name of 65 characters' => ['gate:role:'.str_repeat('r', 65)];
        yield 'a trailing newline' => ["gate:role:admin\n"];
    }

    #[DataProvider('accepted')]
    public function test_a_role_ability_with_a_valid_name_is_a_package_ability(string $ability): void
    {
        $this->assertTrue(Ability::isPackageAbility($ability));
    }

    #[DataProvider('refused')]
    public function test_a_role_ability_with_an_invalid_name_is_not_a_package_ability(string $ability): void
    {
        $this->assertFalse(Ability::isPackageAbility($ability));
    }

    public function test_issue_stores_read_the_ceiling_and_the_role_ability(): void
    {
        $new = TokenIssuer::issue('Ana', 'ops-team', Ceiling::Internal, Carbon::now()->addDays(30), 'support');

        $token = GateAccessToken::query()->findOrFail((int) $new->accessToken->getKey());

        $this->assertEqualsCanonicalizing(['gate:read', 'gate:ceiling:internal', 'gate:role:support'], $token->getAttribute('abilities'));
    }
}
