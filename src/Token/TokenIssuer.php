<?php

declare(strict_types=1);

namespace ContextGate\Token;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Laravel\Sanctum\NewAccessToken;

final class TokenIssuer
{
    public const MAX_DAYS = 90;

    private const MAX_ISSUER_LENGTH = 120;

    private const MAX_HOLDER_LENGTH = 255;

    /**
     * Issues a gate token with gate:read and one ceiling ability, owned by the GateTokenHolder named $holder (created
     * on first use), and records its issuer in the same transaction on the token model's connection. Refuses an expiry
     * in the past or beyond 90 days, and an issuer over 120 characters, before anything is written.
     *
     * @throws InvalidArgumentException
     */
    public static function issue(string $holder, string $issuer, Ceiling $ceiling, DateTimeInterface $expiresAt): NewAccessToken
    {
        $now = Carbon::now('UTC');

        if ($expiresAt <= $now || $expiresAt > $now->copy()->addDays(self::MAX_DAYS)) {
            throw new InvalidArgumentException('Token expiry must be in the future and at most 90 days away.');
        }

        if (mb_strlen($holder) > self::MAX_HOLDER_LENGTH) {
            throw new InvalidArgumentException('Token holder name must be at most 255 characters.');
        }

        if (mb_strlen($issuer) > self::MAX_ISSUER_LENGTH) {
            throw new InvalidArgumentException('Token issuer must be at most 120 characters.');
        }

        $connection = DB::connection((new GateAccessToken())->getConnectionName());

        return $connection->transaction(function () use ($connection, $holder, $issuer, $ceiling, $expiresAt, $now): NewAccessToken {
            $owner = GateTokenHolder::query()->firstOrCreate(['name' => $holder]);
            $new = $owner->createToken($holder, [Ability::READ, Ability::CEILING_PREFIX.$ceiling->value], $expiresAt);

            $connection->table('gate_token_issuers')->insert([
                'token_id' => $new->accessToken->getKey(),
                'issuer' => $issuer,
                'created_at' => $now,
            ]);

            return $new;
        });
    }
}
