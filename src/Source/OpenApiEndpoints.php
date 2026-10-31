<?php

declare(strict_types=1);

namespace ContextGate\Source;

/**
 * FR-002, FR-011, research R4: one row per operation of a parsed OpenAPI document whose examples are already dropped.
 * An internal ref is expanded once per top-level schema; an external ref is listed as ref.external and never requested.
 */
final class OpenApiEndpoints
{
    private const METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /** @param array<array-key, mixed> $document */
    public function __construct(private readonly array $document)
    {
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        $rows = [];
        $paths = is_array($this->document['paths'] ?? null) ? $this->document['paths'] : [];

        foreach ($paths as $path => $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach (self::METHODS as $method) {
                $operation = $item[$method] ?? null;

                if (! is_array($operation)) {
                    continue;
                }

                $rows[] = [
                    'method' => strtoupper($method),
                    'path' => (string) $path,
                    'parameters' => $this->parameters([...$this->items($item['parameters'] ?? null), ...$this->items($operation['parameters'] ?? null)]),
                    'request_schema' => $this->schemaOf($operation['requestBody'] ?? null),
                    'response_schemas' => $this->responses($operation['responses'] ?? null),
                    'security' => $this->securityNames($operation),
                ];
            }
        }

        return $rows;
    }

    /** @return list<mixed> */
    private function items(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param list<mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function parameters(array $parameters): array
    {
        $rows = [];

        foreach ($parameters as $parameter) {
            $parameter = $this->target($parameter);

            if (! is_array($parameter)) {
                continue;
            }

            $rows[] = [
                'name' => $parameter['name'] ?? null,
                'in' => $parameter['in'] ?? null,
                'required' => ($parameter['required'] ?? false) === true,
                'schema' => $this->expand($parameter['schema'] ?? null),
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> status => schema, or null where the response has no schema */
    private function responses(mixed $responses): array
    {
        $rows = [];

        foreach (is_array($responses) ? $responses : [] as $status => $response) {
            $rows[(string) $status] = $this->schemaOf($response);
        }

        return $rows;
    }

    /** The schema of the first media type (application/json when present) of a request body or response. */
    private function schemaOf(mixed $node): mixed
    {
        $node = $this->target($node);
        $content = is_array($node) && is_array($node['content'] ?? null) ? $node['content'] : [];
        $media = $content['application/json'] ?? reset($content);

        return $this->expand(is_array($media) ? ($media['schema'] ?? null) : null);
    }

    /**
     * The security scheme names an operation requires, or the document's when the operation sets none.
     *
     * @param array<array-key, mixed> $operation
     */
    private function securityNames(array $operation): string
    {
        $requirements = $operation['security'] ?? $this->document['security'] ?? [];
        $names = [];

        foreach (is_array($requirements) ? $requirements : [] as $requirement) {
            if (is_array($requirement)) {
                $names = [...$names, ...array_keys($requirement)];
            }
        }

        return implode(', ', array_unique($names));
    }

    /** Follows a local ref one step, so a parameter, response or request body in components is read. */
    private function target(mixed $node): mixed
    {
        $ref = is_array($node) ? ($node['$ref'] ?? null) : null;

        return is_string($ref) && str_starts_with($ref, '#/') ? $this->pointer($ref) : $node;
    }

    /**
     * A repeat or a cycle of an internal ref stays {"$ref": original}; an unresolved internal ref stays a pointer too.
     *
     * @param array<string, true> $seen the refs already expanded in this top-level schema
     */
    private function expand(mixed $node, array &$seen = []): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        $ref = $node['$ref'] ?? null;

        if (is_string($ref)) {
            if (! str_starts_with($ref, '#/')) {
                return ['$ref' => 'ref.external'];
            }

            $target = $this->pointer($ref);

            if (! is_array($target) || isset($seen[$ref])) {
                return ['$ref' => $ref];
            }

            $seen[$ref] = true;

            return $this->expand($target, $seen);
        }

        $out = [];

        foreach ($node as $key => $child) {
            $out[$key] = $this->expand($child, $seen);
        }

        return $out;
    }

    /** The node a local JSON pointer names, or null when it names nothing. */
    private function pointer(string $ref): mixed
    {
        $node = $this->document;

        foreach (explode('/', substr($ref, 2)) as $segment) {
            $key = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if (! is_array($node) || ! array_key_exists($key, $node)) {
                return null;
            }

            $node = $node[$key];
        }

        return $node;
    }
}
