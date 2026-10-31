<?php

declare(strict_types=1);

namespace ContextGate\Source\Pseudo;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;

/**
 * Contract of the openapi.endpoints rows (US1). The three schema-bearing fields hold JSON text, so the firewall
 * normalizes, redacts and quarantines them as untrusted text. Each column is classified in the catalog under
 * source_openapi_endpoints.
 */
final class OpenApiEndpointsContract extends ContextContract
{
    public function fields(): array
    {
        return [
            'version' => Field::plain(),
            'method' => Field::plain(),
            'path' => Field::plain(),
            'parameters' => Field::text(),
            'request_schema' => Field::text(),
            'response_schemas' => Field::text(),
            'security' => Field::plain(),
        ];
    }

    public function model(): string
    {
        return SourceOpenApiEndpointRow::class;
    }
}
