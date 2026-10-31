<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Source\OpenApiEndpoints;
use ContextGate\Tests\TestCase;

/** FR-002, research R4: ref expansion across all endpoints stays within the document's byte budget. */
class OpenApiEndpointsBudgetTest extends TestCase
{
    public function test_expansion_stops_once_the_budget_is_spent_and_keeps_a_pointer(): void
    {
        $rows = (new OpenApiEndpoints($this->document(), 100))->rows();

        $this->assertArrayHasKey('type', $rows[0]['response_schemas']['200']);
        $this->assertSame(['$ref' => '#/components/schemas/Big'], $rows[19]['response_schemas']['200']);
    }

    public function test_a_large_budget_expands_every_endpoint(): void
    {
        $rows = (new OpenApiEndpoints($this->document(), 1_000_000))->rows();

        $this->assertArrayHasKey('type', $rows[19]['response_schemas']['200']);
    }

    public function test_long_strings_spend_the_budget_too(): void
    {
        $document = $this->document();
        $document['components']['schemas']['Big'] = ['type' => 'object', 'description' => str_repeat('x', 5000)];

        $rows = (new OpenApiEndpoints($document, 1000))->rows();

        $this->assertArrayHasKey('type', $rows[0]['response_schemas']['200']);
        $this->assertSame(['$ref' => '#/components/schemas/Big'], $rows[1]['response_schemas']['200']);
    }

    /** @return array<string, mixed> twenty endpoints that all return one large component */
    private function document(): array
    {
        $paths = [];

        for ($i = 0; $i < 20; $i++) {
            $paths["/p{$i}"] = ['get' => ['responses' => ['200' => ['content' => ['application/json' => [
                'schema' => ['$ref' => '#/components/schemas/Big'],
            ]]]]]];
        }

        return [
            'openapi' => '3.1.0',
            'paths' => $paths,
            'components' => ['schemas' => ['Big' => [
                'type' => 'object',
                'properties' => array_fill_keys(range(1, 50), ['type' => 'string']),
            ]]],
        ];
    }
}
