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

        $serialized = json_encode($payload->rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach ($this->canaries as $canary) {
            if (str_contains($serialized, $canary)) {
                $payload->flags[] = self::FLAG;

                return $payload;
            }
        }

        return $next($payload);
    }
}
