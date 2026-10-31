<?php

declare(strict_types=1);

namespace ContextGate\Token;

/**
 * The ceilings a token may carry: the spec 002 classes without `secret` (research R3), least to most sensitive.
 */
enum Ceiling: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Personal = 'personal';
}
