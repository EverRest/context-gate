<?php

declare(strict_types=1);

namespace ContextGate\Source;

use ContextGate\Server\ReasonCode;
use JsonException;
use Symfony\Component\Yaml\Exception\ExceptionInterface;
use Symfony\Component\Yaml\Yaml;

/** R1, FR-010: parses one OpenAPI 3.x document. Every refusal carries a reason code and no document content. */
final class OpenApiParser
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    private const JSON_DEPTH = 64;

    /** @return array<array-key, mixed> */
    public function parse(string $document, string $format): array
    {
        if (strlen($document) > self::MAX_BYTES) {
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
                'yaml' => Yaml::parse($document),
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
}
