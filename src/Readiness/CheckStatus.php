<?php

declare(strict_types=1);

namespace ContextGate\Readiness;

enum CheckStatus: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
}
