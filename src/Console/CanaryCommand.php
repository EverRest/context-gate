<?php

declare(strict_types=1);

namespace ContextGate\Console;

use Illuminate\Console\Command;

/**
 * spec 004 US5 (FR-017): prints one email canary and one token canary, and where to place them. Writes no file; the
 * values come from random_bytes and are fake by shape (example.invalid), never taken from the project.
 */
class CanaryCommand extends Command
{
    protected $signature = 'gate:canary';

    protected $description = 'Generate canary values and print where to place them; it changes no file';

    public function handle(): int
    {
        $email = 'canary-'.bin2hex(random_bytes(4)).'@example.invalid';
        $token = 'CANARY_'.bin2hex(random_bytes(12));

        $this->line('Canary values. Place each one exactly as shown; a canary is fake and must never be real data.');
        $this->line("  documentation     {$email}");
        $this->line("  OpenAPI examples  {$email}");
        $this->line("  configuration     CONTEXT_GATE_CANARY_EMAIL={$email}");
        $this->line("  configuration     CONTEXT_GATE_CANARY_TOKEN={$token}");
        $this->line('Both configuration values go into canaries.values; a canary that is not listed there is never checked.');

        return self::SUCCESS;
    }
}
