<?php

declare(strict_types=1);

namespace ContextGate\Token;

/**
 * The ceilings a token may carry: the classes without `secret`, least to most sensitive.
 */
enum Ceiling: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Personal = 'personal';
}
