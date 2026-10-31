<?php

declare(strict_types=1);

namespace ContextGate\Source;

/**
 * FR-003, research R3: drop mode removes example values (example, examples, x-example, string default). Keys of a
 * name map (a schema's properties, the components, the responses) are names, so a name such as "example" is kept.
 */
final class ExamplePolicy
{
    private const KEYWORDS = ['example', 'examples', 'x-example'];

    /** Values whose keys are user-chosen names; each child is one object whose keys are keywords. */
    private const NAME_MAPS = [
        'properties', 'patternProperties', 'dependentSchemas', '$defs', 'definitions', 'schemas', 'paths', 'pathItems',
        'responses', 'parameters', 'headers', 'requestBodies', 'securitySchemes', 'links', 'callbacks', 'encodings',
        'content',
    ];

    /** Literal data (an enum list, a const, a non-string default) is copied as it is. */
    private const LITERALS = ['enum', 'const', 'default'];

    /**
     * @param array<array-key, mixed> $document
     * @return array<array-key, mixed>
     */
    public function drop(array $document): array
    {
        return $this->walk($document, false);
    }

    /**
     * @param array<array-key, mixed> $node
     * @return array<array-key, mixed>
     */
    private function walk(array $node, bool $isNameMap): array
    {
        $out = [];

        foreach ($node as $key => $value) {
            if ($isNameMap) {
                $out[$key] = is_array($value) ? $this->walk($value, false) : $value;

                continue;
            }

            if (in_array($key, self::KEYWORDS, true) || ($key === 'default' && is_string($value))) {
                continue;
            }

            $out[$key] = match (true) {
                ! is_array($value), in_array($key, self::LITERALS, true) => $value,
                in_array($key, self::NAME_MAPS, true) => $this->walk($value, true),
                default => $this->walk($value, false),
            };
        }

        return $out;
    }
}
