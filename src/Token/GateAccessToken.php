<?php

declare(strict_types=1);

namespace ContextGate\Token;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * The gate's own Sanctum token model, stored in gate_access_tokens. It is not set as Sanctum's global personal access
 * token model, so the host application's guards and its personal_access_tokens rows are untouched.
 */
final class GateAccessToken extends PersonalAccessToken
{
    protected $table = 'gate_access_tokens';
}
