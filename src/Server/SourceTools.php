<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Source\Pseudo\ConfigKeysContract;
use ContextGate\Source\Pseudo\DocsContract;
use ContextGate\Source\Pseudo\OpenApiEndpointsContract;
use ContextGate\Source\Pseudo\RoutesContract;
use ContextGate\Source\Pseudo\SchemaItemsContract;
use ContextGate\Server\Sources\ConfigKeysTool;
use ContextGate\Server\Sources\DocsReadTool;
use ContextGate\Server\Sources\OpenApiEndpointsTool;
use ContextGate\Server\Sources\RoutesListTool;
use ContextGate\Server\Sources\SchemaDescribeTool;

/**
 * The source tools, with their contracts. Each entry is a config
 * tool entry: disabled until the operator enables it and sets its gate. Rows are flattened by the tool classes so
 * the firewall projection keeps them.
 */
final class SourceTools
{
    /** tool name => [tool class, contract class] */
    private const TOOLS = [
        'routes.list' => [RoutesListTool::class, RoutesContract::class],
        'schema.describe' => [SchemaDescribeTool::class, SchemaItemsContract::class],
        'config.keys' => [ConfigKeysTool::class, ConfigKeysContract::class],
        'docs.read' => [DocsReadTool::class, DocsContract::class],
        'openapi.endpoints' => [OpenApiEndpointsTool::class, OpenApiEndpointsContract::class],
    ];

    /** @return array<string, array<string, mixed>> config tool entries by tool name */
    public static function entries(): array
    {
        $entries = [];

        foreach (self::TOOLS as $name => [$class, $contract]) {
            $entries[$name] = [
                'enabled' => false,
                'class' => $class,
                'contract' => $contract,
                'fingerprint' => Fingerprint::of(new $class()),
            ];
        }

        return $entries;
    }

    /** @return list<array<array-key, mixed>> the array items of a value, in order; anything else gives none */
    public static function items(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    /** A scalar as text; anything else is empty. */
    public static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** A string, or null for anything else (a route without a name). */
    public static function nullable(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /** A list of scalars joined with a comma, so one row field carries it. */
    public static function joined(mixed $value): string
    {
        return is_array($value) ? implode(', ', array_map(static fn (mixed $item): string => self::text($item), $value)) : '';
    }
}
