<?php

declare(strict_types=1);

namespace ContextGate\Server;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Where an exception was thrown, on the receipt channel, and nothing else: an exception message can quote raw data,
 * so only the receipt reference, the class, the file's base name and the line are written. Never the message,
 * the trace or the previous exception.
 */
final class Failure
{
    /** @var list<Throwable> exceptions a stage handled itself, waiting for the receipt reference of the call */
    private static array $held = [];

    public static function note(Throwable $e, string $reference): void
    {
        Log::channel(config('context-gate.channel'))->info((string) json_encode([
            'reference' => $reference,
            'exception' => $e::class,
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
        ], JSON_THROW_ON_ERROR));
    }

    /** A stage that drops its field or refuses the call has no reference yet; the call notes it once it has. */
    public static function hold(Throwable $e): void
    {
        self::$held[] = $e;
    }

    public static function release(string $reference): void
    {
        $held = self::$held;
        self::$held = [];

        foreach ($held as $e) {
            self::note($e, $reference);
        }
    }

    /** Forgets what an earlier call left behind. */
    public static function clear(): void
    {
        self::$held = [];
    }
}
