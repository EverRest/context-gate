<?php

declare(strict_types=1);

namespace ContextGate\Settings;

/**
 * The two security profiles. There is no permissive value (constitution III).
 *
 * @internal May change without notice.
 */
enum SecurityProfile: string
{
    case Strict = 'strict';

    case Balanced = 'balanced';
}
