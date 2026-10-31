<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Server\ReasonCode;
use ContextGate\Source\OpenApiEndpoints;
use ContextGate\Source\SourceFailure;
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

    public function test_one_large_inline_parameter_used_by_many_operations_is_refused_not_copied_each_time(): void
    {
        $paths = [];

        for ($i = 0; $i < 200; $i++) {
            $paths["/p{$i}"] = ['get' => ['parameters' => [['$ref' => '#/components/parameters/big']]]];
        }

        $document = ['openapi' => '3.1.0', 'paths' => $paths, 'components' => ['parameters' => ['big' => [
            'name' => 'q', 'in' => 'query',
            'schema' => ['type' => 'object', 'properties' => array_fill_keys(range(1, 50), ['type' => 'string'])],
        ]]]];

        try {
            (new OpenApiEndpoints($document, 2000))->rows();
            $this->fail('Copying one component per operation must stay within the budget');
        } catch (SourceFailure $failure) {
            $this->assertSame(ReasonCode::SourceTooLarge, $failure->reason);
        }
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
