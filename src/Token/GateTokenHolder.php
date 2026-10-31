<?php

declare(strict_types=1);

namespace ContextGate\Token;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Sanctum\Contracts\HasApiTokens as ApiTokensContract;
use Laravel\Sanctum\HasApiTokens;

/**
 * Owns the gate's tokens (gate:token --holder). Its tokens are GateAccessToken rows, never the host's.
 */
final class GateTokenHolder extends Model implements ApiTokensContract
{
    use HasApiTokens;

    protected $table = 'gate_token_holders';

    protected $guarded = [];

    public $timestamps = false;

    /** @return MorphMany<GateAccessToken, $this> */
    public function tokens(): MorphMany
    {
        return $this->morphMany(GateAccessToken::class, 'tokenable');
    }
}
