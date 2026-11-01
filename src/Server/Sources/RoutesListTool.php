<?php

declare(strict_types=1);

namespace ContextGate\Server\Sources;

use ContextGate\Server\SourceTools;
use ContextGate\Server\Tool;
use ContextGate\Source\RoutesSource;
use ContextGate\Source\SourceFailure;
use ContextGate\Source\SourceRequest;
use Laravel\Mcp\Response;

/** routes.list (spec 003 US1): one row per route. */
final class RoutesListTool extends Tool
{
    public function name(): string
    {
        return 'routes.list';
    }

    public function description(): string
    {
        return 'Lists the application routes with method, uri, name, controller, form rule names and middleware. '
            .'Takes no arguments and returns every route; routes the operator excluded are absent.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function handle(array $arguments): Response
    {
        $result = (new RoutesSource())->read(new SourceRequest([]));

        if (! $result->ok) {
            throw SourceFailure::from($result);
        }

        $rows = [];

        foreach (SourceTools::items($result->data['routes'] ?? null) as $route) {
            $rows[] = [
                'method' => SourceTools::text($route['method'] ?? null),
                'uri' => SourceTools::text($route['uri'] ?? null),
                'name' => SourceTools::nullable($route['name'] ?? null),
                'controller' => SourceTools::nullable($route['controller'] ?? null),
                'rule_names' => SourceTools::joined($route['rule_names'] ?? null),
                'middleware' => SourceTools::joined($route['middleware'] ?? null),
            ];
        }

        return Response::text((string) json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
