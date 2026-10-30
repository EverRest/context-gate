<?php

declare(strict_types=1);

namespace ContextGate\Server;

use Illuminate\Support\Facades\Log;

final class Receipt
{
    /** Writes one metadata-only JSON line and returns the receipt id. Arguments and results never reach here. */
    public static function write(string $caller, ?string $tool, ?ReasonCode $reason = null): string
    {
        $id = 'r_'.bin2hex(random_bytes(4));

        $line = json_encode([
            'id' => $id,
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
            'caller' => $caller,
            'tool' => $tool,
            'decision' => $reason?->decision() ?? 'allow',
            'reason' => $reason?->value,
            'rules' => (object) [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        Log::channel(config('context-gate.channel'))->info($line);

        return $id;
    }
}
