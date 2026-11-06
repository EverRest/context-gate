<?php

declare(strict_types=1);

namespace ContextGate\Token;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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
     * Sanctum looks the id part up as given. On PostgreSQL an id that is not a bigint is a query error, not a miss, so
     * it is refused here. Eighteen digits always fit a signed bigint.
     *
     * @param string $token
     */
    public static function findToken($token): ?static
    {
        if (str_contains($token, '|') && preg_match('/^\d{1,18}\|/', $token) !== 1) {
            return null;
        }

        return parent::findToken($token);
    }

    /**
     * expires_at is a UTC instant: stored as a UTC wall clock and read back as UTC, whatever app.timezone is. Sanctum's
     * datetime cast reads the column in the app zone, which moves the expiry by the zone's offset.
     */
    protected function expiresAt(): Attribute
    {
        return Attribute::make(
            // Fail closed: Sanctum reads null as "never expires", so only a real null is null.
            get: static fn (mixed $value): ?Carbon => match (true) {
                $value === null => null,
                is_string($value) => Carbon::parse($value, 'UTC'),
                $value instanceof DateTimeInterface => Carbon::instance($value)->utc(),
                default => Carbon::createFromTimestampUTC(0),
            },
            set: static fn (?DateTimeInterface $value): ?string => $value === null
                ? null
                : Carbon::instance($value)->utc()->format('Y-m-d H:i:s'),
        );
    }

    /**
     * Built on the holder model directly, never through the morph map: under a required map, Laravel 13 refuses a
     * type the host has no alias for, and the host's map must not need an entry for the gate. A row owned by any other
     * type resolves to no holder.
     *
     * @return MorphTo<GateTokenHolder, $this>
     */
    public function tokenable(): MorphTo
    {
        $holder = new GateTokenHolder();
        $query = $holder->newQuery();

        if ($this->getAttribute('tokenable_type') !== GateTokenHolder::class) {
            $query->whereRaw('1 = 0');
        }

        return $this->newMorphTo($query, $this, 'tokenable_id', $holder->getKeyName(), 'tokenable_type', 'tokenable');
    }
}
