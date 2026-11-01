<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

use Closure;

/**
 * Stage 8: a configured canary value found in the serialized rows blocks the response. Runs after
 * Normalizer, so text split by invisible characters is already joined. Matching ignores letter case and folds common Latin/Cyrillic/Greek look-alikes on both sides. Empty values
 * are ignored: str_contains with an empty needle would block everything.
 *
 * @internal May change without notice.
 */
final class CanaryCheck implements Stage
{
    public const FLAG = 'canary.hit';

    private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** Common Cyrillic and Greek look-alikes (lowercase) mapped to Latin. ponytail: not the full Unicode confusables table. */
    private const CONFUSABLES = [
        'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'у' => 'y', 'х' => 'x', 'і' => 'i',
        'ј' => 'j', 'ѕ' => 's', 'ӏ' => 'l', 'һ' => 'h', 'ԁ' => 'd', 'ԛ' => 'q', 'ԝ' => 'w',
        'α' => 'a', 'ο' => 'o', 'ν' => 'v', 'ρ' => 'p', 'ι' => 'i', 'κ' => 'k', 'υ' => 'u', 'χ' => 'x',
    ];

    /** @var list<string> */
    private readonly array $canaries;

    /**
     * @param list<string> $canaries from configuration key context-gate.canaries; normalised like the data, so a
     *                               canary in decomposed form still matches composed text
     */
    public function __construct(array $canaries)
    {
        $normalised = array_map(Normalizer::text(...), $canaries);
        $this->canaries = array_values(array_filter($normalised, static fn (string $canary): bool => $canary !== ''));
    }

    public function handle(Payload $payload, Closure $next): Payload
    {
        if ($this->canaries === []) {
            return $next($payload);
        }

        $lower = self::skeleton((string) json_encode($payload->rows, self::FLAGS));

        foreach ($this->canaries as $canary) {
            // The needle is the canary as it appears inside the serialized JSON (quotes and backslashes escaped).
            if (str_contains($lower, self::skeleton(self::escaped($canary)))) {
                $payload->flags[] = self::FLAG;

                return $payload;
            }
        }

        return $next($payload);
    }

    /** Lowercase, then look-alike letters folded to Latin. */
    private static function skeleton(string $text): string
    {
        return strtr(mb_strtolower($text), self::CONFUSABLES);
    }

    /** The canary as json_encode writes it inside a string, without the surrounding quotes. */
    private static function escaped(string $canary): string
    {
        return substr(json_encode($canary, self::FLAGS), 1, -1);
    }
}
