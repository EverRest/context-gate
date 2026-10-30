<?php

declare(strict_types=1);

namespace ContextGate\Server;

use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

final class ArgumentValidator
{
    /**
     * Returns the first problem with the arguments, or null when they match the tool's schema.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    public function validate(Tool $tool, array $arguments): ?string
    {
        $schema = JsonSchema::fromArray($tool->schema())->toArray();
        $properties = $schema['properties'] ?? [];

        foreach (array_keys($arguments) as $key) {
            if (! is_string($key) || ! array_key_exists($key, $properties)) {
                return "Argument [{$key}] is not allowed for tool [{$tool->name()}].";
            }
        }

        $rules = [];

        foreach ($properties as $key => $property) {
            $rules[$key] = array_filter([
                in_array($key, $schema['required'] ?? [], true) ? 'required' : 'sometimes',
                $this->typeRule($property['type'] ?? null),
            ]);
        }

        $validator = Validator::make($arguments, $rules);

        return $validator->fails() ? $validator->errors()->first() : null;
    }

    private function typeRule(mixed $type): ?string
    {
        return match ($type) {
            'string' => 'string',
            'integer' => 'integer',
            'number' => 'numeric',
            'boolean' => 'boolean',
            'array', 'object' => 'array',
            default => null,
        };
    }
}
