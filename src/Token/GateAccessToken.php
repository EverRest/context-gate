<?php

declare(strict_types=1);

namespace ContextGate\Token;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The gate's own Sanctum token model, stored in gate_access_tokens. It is not set as Sanctum's global personal access
 * token model, so the host application's guards and its personal_access_tokens rows are untouched.
 */
final class GateAccessToken extends PersonalAccessToken
{
    protected $table = 'gate_access_tokens';

    /**
     * expires_at is a UTC instant: stored as a UTC wall clock and read back as UTC, whatever app.timezone is. Sanctum's
     * datetime cast reads the column in the app zone, which moves the expiry by the zone's offset.
     */
    protected function expiresAt(): Attribute
    {
        return Attribute::make(
            get: static fn (mixed $value): ?Carbon => is_string($value) ? Carbon::parse($value, 'UTC') : null,
            set: static fn (?DateTimeInterface $value): ?string => $value === null
                ? null
                : Carbon::instance($value)->utc()->format('Y-m-d H:i:s'),
        );
    }
}
