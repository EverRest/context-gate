<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Source\OpenApiEndpoints;
use ContextGate\Tests\TestCase;

/** FR-003: the request body's Media Type examples are listed only when the examples mode keeps them. */
class OpenApiEndpointsExamplesTest extends TestCase
{
    private const EXAMPLES = ['fixture' => ['value' => ['email' => 'canary-fixture@example.invalid']]];

    public function test_redacted_mode_lists_the_media_type_examples_map(): void
    {
        $rows = (new OpenApiEndpoints($this->document(), 1_000_000, true))->rows();

        $this->assertSame(self::EXAMPLES, $rows[0]['examples']);
    }

    public function test_drop_mode_gives_null_even_when_the_document_still_holds_the_examples(): void
    {
        $rows = (new OpenApiEndpoints($this->document(), 1_000_000))->rows();

        $this->assertNull($rows[0]['examples']);
    }

    public function test_an_operation_without_media_examples_gives_null_in_redacted_mode(): void
    {
        $document = $this->document();
        unset($document['paths']['/users']['post']['requestBody']['content']['application/json']['examples']);

        $rows = (new OpenApiEndpoints($document, 1_000_000, true))->rows();

        $this->assertNull($rows[0]['examples']);
    }

    public function test_the_schema_columns_are_unchanged_by_the_examples_column(): void
    {
        $rows = (new OpenApiEndpoints($this->document(), 1_000_000, true))->rows();

        $this->assertSame(['type' => 'object'], $rows[0]['request_schema']);
    }

    /** @return array<array-key, mixed> */
    private function document(): array
    {
        return [
            'openapi' => '3.1.0',
            'paths' => ['/users' => ['post' => [
                'requestBody' => ['content' => ['application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/NewUser'],
                    'examples' => self::EXAMPLES,
                ]]],
                'responses' => ['201' => ['description' => 'Created']],
            ]]],
            'components' => ['schemas' => ['NewUser' => ['type' => 'object']]],
        ];
    }
}
