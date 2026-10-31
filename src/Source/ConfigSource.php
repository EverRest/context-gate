<?php

declare(strict_types=1);

namespace ContextGate\Source;

use ContextGate\Server\ReasonCode;

/**
 * config.keys (FR-007, FR-008, research R4). Walks config() and keeps only the dotted key, the leaf type and
 * the env name when it is safe to show. Values are read for their type and then dropped.
 */
final class ConfigSource implements Source
{
    public function name(): string
    {
        return 'config.keys';
    }

    public function read(SourceRequest $request): SourceResult
    {
        $prefix = $request->arguments['prefix'] ?? '';
        if (! is_string($prefix)) {
            return SourceResult::failed(ReasonCode::InputInvalid);
        }

        $envMap = config('context-gate.sources.config.env_map', []);
        $envMap = is_array($envMap) ? $envMap : [];

        $keys = [];
        foreach ($this->walk(config()->all(), '') as $key => $type) {
            if ($prefix !== '' && $key !== $prefix && ! str_starts_with($key, $prefix . '.')) {
                continue;
            }

            $variable = $envMap[$key] ?? null;
            $keys[] = [
                'key' => $key,
                'type' => $type,
                'env' => match (true) {
                    ! is_string($variable) => null,
                    SensitiveNames::matches($key, $variable) => 'env:<hidden>',
                    default => 'env:' . $variable,
                },
            ];
        }

        return SourceResult::ok(['keys' => $keys]);
    }

    /**
     * Associative arrays are sections and are walked; lists, empty arrays and scalars are leaves. A list is
     * typed as a whole and its items are never visited.
     *
     * @param array<array-key, mixed> $items
     * @return iterable<string, string> dotted key => leaf type
     */
    private function walk(array $items, string $parent): iterable
    {
        foreach ($items as $name => $value) {
            // A key segment that is itself a secret or an address (FR-007) is masked; identifiers stay visible.
            $segment = self::secretSegment((string) $name) ? '<SECRET>' : (string) $name;
            $key = $parent === '' ? $segment : $parent . '.' . $segment;

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                yield from $this->walk($value, $key);
            } else {
                yield $key => $this->typeOf($value);
            }
        }
    }

    /** FR-007: 16 or more key-like characters with a digit or a hyphen, or an address (an `@`). */
    private static function secretSegment(string $segment): bool
    {
        return preg_match('/^(?=.*[0-9-])[A-Za-z0-9_-]{16,}$/', $segment) === 1 || str_contains($segment, '@');
    }

    private function typeOf(mixed $value): string
    {
        return match (true) {
            is_string($value) => 'string',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_bool($value) => 'bool',
            is_null($value) => 'null',
            is_array($value) => 'array',
            default => 'object',
        };
    }
}
