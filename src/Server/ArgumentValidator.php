<?php

declare(strict_types=1);

namespace ContextGate\Server;

final class ArgumentValidator
{
    /**
     * Returns the first problem with the arguments, or null when they match the tool's schema. Validates type (incl.
     * unions), enum, min/maxLength, pattern, minimum/maximum, min/maxItems, items and nested properties/required.
     * ponytail: other JSON Schema keywords (oneOf, $ref, format ...) are not evaluated; add when a tool needs them.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    public function validate(Tool $tool, array $arguments): ?string
    {
        $schema = $tool->schema();
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        foreach (array_keys($arguments) as $key) {
            if (! is_string($key) || ! array_key_exists($key, $properties)) {
                return "Argument [{$key}] is not allowed for tool [{$tool->name()}].";
            }
        }

        return $this->object($arguments, $schema, '');
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  array<array-key, mixed>  $schema
     */
    private function object(array $value, array $schema, string $path): ?string
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        foreach ($required as $name) {
            if (! is_string($name) || ! array_key_exists($name, $value)) {
                return 'Argument ['.$this->join($path, (string) (is_string($name) ? $name : '')).'] is required.';
            }
        }

        foreach ($value as $key => $item) {
            $property = $properties[$key] ?? null;

            if (! is_array($property)) {
                if ($path !== '' && $properties !== [] && ($schema['additionalProperties'] ?? false) !== true) {
                    return 'Argument ['.$this->join($path, (string) $key).'] is not allowed.';
                }

                continue;
            }

            $error = $this->check($item, $property, $this->join($path, (string) $key));

            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /** @param  array<array-key, mixed>  $schema */
    private function check(mixed $value, array $schema, string $path): ?string
    {
        $types = isset($schema['type']) ? (array) $schema['type'] : [];

        if ($types !== [] && ! array_filter($types, fn (mixed $t): bool => is_string($t) && $this->isType($value, $t))) {
            return "Argument [{$path}] must be of type ".implode('|', array_filter($types, 'is_string')).'.';
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return "Argument [{$path}] is not one of the allowed values.";
        }

        $error = match (true) {
            is_string($value) => $this->string($value, $schema, $path),
            is_int($value) || is_float($value) => $this->number($value, $schema, $path),
            is_array($value) && array_is_list($value) && ! in_array('object', $types, true) => $this->list($value, $schema, $path),
            is_array($value) => $this->object($value, $schema, $path),
            default => null,
        };

        return $error;
    }

    /** @param  array<array-key, mixed>  $schema */
    private function string(string $value, array $schema, string $path): ?string
    {
        $length = mb_strlen($value);

        return match (true) {
            isset($schema['minLength']) && $length < (int) $schema['minLength'] => "Argument [{$path}] is too short.",
            isset($schema['maxLength']) && $length > (int) $schema['maxLength'] => "Argument [{$path}] is too long.",
            isset($schema['pattern']) && is_string($schema['pattern'])
                && @preg_match('~'.str_replace('~', '\~', $schema['pattern']).'~u', $value) !== 1 => "Argument [{$path}] does not match the required pattern.",
            default => null,
        };
    }

    /** @param  array<array-key, mixed>  $schema */
    private function number(int|float $value, array $schema, string $path): ?string
    {
        return match (true) {
            isset($schema['minimum']) && is_numeric($schema['minimum']) && $value < $schema['minimum'] => "Argument [{$path}] is below the minimum.",
            isset($schema['maximum']) && is_numeric($schema['maximum']) && $value > $schema['maximum'] => "Argument [{$path}] is above the maximum.",
            default => null,
        };
    }

    /**
     * @param  list<mixed>  $value
     * @param  array<array-key, mixed>  $schema
     */
    private function list(array $value, array $schema, string $path): ?string
    {
        if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
            return "Argument [{$path}] has too few items.";
        }

        if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
            return "Argument [{$path}] has too many items.";
        }

        if (! is_array($schema['items'] ?? null)) {
            return null;
        }

        foreach ($value as $index => $item) {
            $error = $this->check($item, $schema['items'], "{$path}.{$index}");

            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    private function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            default => false,
        };
    }

    private function join(string $path, string $key): string
    {
        return $path === '' ? $key : "{$path}.{$key}";
    }
}
