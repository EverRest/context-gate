<?php

declare(strict_types=1);

namespace ContextGate\Source;

use ContextGate\Pipeline\Caps;
use ContextGate\Server\ReasonCode;
use JsonException;
use Symfony\Component\Yaml\Exception\ExceptionInterface;
use Symfony\Component\Yaml\Yaml;

/** Parses one OpenAPI 3.x document. Every refusal carries a reason code and no document content. */
final class OpenApiParser
{
    private const JSON_DEPTH = 64;

    private const YAML_MAX_ALIASES = 100;

    private readonly int $maxBytes;

    /** @param int $maxBytes the effective document size from the active profile, clamped to its cap */
    public function __construct(int $maxBytes = Caps::OPENAPI_BYTES)
    {
        $this->maxBytes = min($maxBytes, Caps::OPENAPI_BYTES);
    }

    /** @return array<array-key, mixed> */
    public function parse(string $document, string $format): array
    {
        if (strlen($document) > $this->maxBytes) {
            throw new SourceFailure(ReasonCode::SourceTooLarge);
        }

        $parsed = $this->decode($document, $format);
        $budget = strlen($document);
        $this->assertWithinNodeBudget($parsed, $budget);
        $version = $parsed['openapi'] ?? null;

        if (! is_scalar($version) || ! str_starts_with((string) $version, '3.')) {
            throw new SourceFailure(ReasonCode::OpenApiVersion);
        }

        return $parsed;
    }

    /** @return array<array-key, mixed> */
    private function decode(string $document, string $format): array
    {
        try {
            $parsed = match ($format) {
                'json' => json_decode($document, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR),
                'yaml' => Yaml::parse($this->guardYaml($document)),
                default => throw new SourceFailure(ReasonCode::SourceRefused),
            };
        } catch (ExceptionInterface|JsonException) {
            throw new SourceFailure(ReasonCode::SourceRefused);
        }

        if (! is_array($parsed)) {
            throw new SourceFailure(ReasonCode::SourceRefused);
        }

        return $parsed;
    }

    // A document without YAML aliases has at most one node per byte; more nodes means aliases expanded.
    private function assertWithinNodeBudget(mixed $node, int &$budget): void
    {
        if (--$budget < 0) {
            throw new SourceFailure(ReasonCode::SourceTooLarge);
        }

        if (is_array($node)) {
            foreach ($node as $child) {
                $this->assertWithinNodeBudget($child, $budget);
            }
        }
    }

    /**
     * Cheap pre-parse guard (before Yaml::parse): too many aliases, or indentation or flow-bracket nesting deeper
     * than JSON_DEPTH. Crude on purpose: brackets inside strings count too, which only makes it stricter.
     */
    private function guardYaml(string $document): string
    {
        $aliases = preg_match_all('/(?:^|[\s\[{,:-])\*[A-Za-z0-9_.-]+/m', $document);
        $indent = preg_match_all('/^ +(?=\S)/m', $document, $m) ? max(array_map('strlen', $m[0])) : 0;
        $depth = 0;
        $max = 0;
        foreach (str_split($document) as $char) {
            $depth += match ($char) {
                '[', '{' => 1,
                ']', '}' => -1,
                default => 0,
            };
            $max = max($max, $depth);
        }

        if ($aliases > self::YAML_MAX_ALIASES || $indent > self::JSON_DEPTH * 2 || $max > self::JSON_DEPTH) {
            throw new SourceFailure(ReasonCode::SourceTooLarge);
        }

        return $document;
    }
}
