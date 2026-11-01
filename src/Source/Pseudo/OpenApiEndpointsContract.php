<?php

declare(strict_types=1);

namespace ContextGate\Source\Pseudo;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;

/**
 * Contract of the openapi.endpoints rows (US1). Strings the document supplies (version, path, security names, and
 * the JSON-text schema fields) are untrusted text, so the firewall normalizes and quarantines them. The method is
 * one of a fixed list the source writes, so it stays plain. Each column is classified in the catalog under
 * source_openapi_endpoints.
 */
final class OpenApiEndpointsContract extends ContextContract
{
    public function fields(): array
    {
        return [
            'version' => Field::text(),
            'method' => Field::plain(),
            'path' => Field::text(),
            'parameters' => Field::text(),
            'request_schema' => Field::text(),
            'examples' => Field::text(),
            'response_schemas' => Field::text(),
            'security' => Field::text(),
        ];
    }

    public function model(): string
    {
        return SourceOpenApiEndpointRow::class;
    }
}
