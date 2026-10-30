<?php

declare(strict_types=1);

namespace ContextGate\Server;

final class Fingerprint
{
    public static function of(Tool $tool): string
    {
        return hash('sha256', $tool->description());
    }
}
