<?php

declare(strict_types=1);

namespace ContextGate\Contracts;

/**
 * Replaces personal data in free text.
 *
 * Contract: must not throw on valid UTF-8, must not keep the input after returning, must never return it longer-lived.
 *
 * @api Supported extension surface: bind your own implementation instead of extending the concrete Redactor.
 */
interface RedactsText
{
    public function redact(string $text): string;
}
