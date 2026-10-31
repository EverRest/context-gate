<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

use Closure;

/**
 * Stage 8 (R8, FR-017): a configured canary value found in the serialized rows blocks the response. Runs after
 * Normalizer, so text split by invisible characters is already joined. Empty values are ignored: str_contains
 * with an empty needle would block everything.
 */
final class CanaryCheck implements Stage
{
    public const FLAG = 'canary.hit';

    private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** @var list<string> */
    private readonly array $canaries;

    /** @param list<string> $canaries from configuration key context-gate.canaries */
    public function __construct(array $canaries)
    {
        $this->canaries = array_values(array_filter($canaries, static fn (string $canary): bool => $canary !== ''));
    }

    public function handle(Payload $payload, Closure $next): Payload
    {
        if ($this->canaries === []) {
            return $next($payload);
        }

        $serialized = json_encode($payload->rows, self::FLAGS);
        $lower = mb_strtolower($serialized);

        foreach ($this->canaries as $canary) {
            // The needle is the canary as it appears inside the serialized JSON (quotes and backslashes escaped).
            // An email is matched in any letter case; other canaries are case-sensitive secrets.
            if (str_contains($canary, '@')) {
                $found = str_contains($lower, mb_strtolower(self::escaped($canary)));
            } else {
                $found = str_contains($serialized, self::escaped($canary));
            }

            if ($found) {
                $payload->flags[] = self::FLAG;

                return $payload;
            }
        }

        return $next($payload);
    }

    /** The canary as json_encode writes it inside a string, without the surrounding quotes. */
    private static function escaped(string $canary): string
    {
        return substr(json_encode($canary, self::FLAGS), 1, -1);
    }
}
