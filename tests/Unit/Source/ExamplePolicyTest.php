<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Source\ExamplePolicy;
use PHPUnit\Framework\TestCase;

/** FR-003 (examples removed in drop mode), FR-004 (schema definitions returned whole), research R3. */
class ExamplePolicyTest extends TestCase
{
    public function test_example_examples_and_x_example_are_removed_in_drop_mode(): void
    {
        $document = [
            'paths' => ['/users' => ['post' => ['requestBody' => ['content' => ['application/json' => [
                'schema' => ['type' => 'object'],
                'example' => ['email' => 'canary-fixture@example.invalid'],
                'examples' => ['one' => ['value' => ['email' => 'canary-fixture@example.invalid']]],
                'x-example' => ['email' => 'canary-fixture@example.invalid'],
            ]]]]]],
        ];

        $dropped = (new ExamplePolicy())->drop($document);

        $this->assertSame(
            ['paths' => ['/users' => ['post' => ['requestBody' => ['content' => ['application/json' => [
                'schema' => ['type' => 'object'],
            ]]]]]]],
            $dropped,
        );
    }

    public function test_string_default_is_removed_and_a_non_string_default_is_kept(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'email' => ['type' => 'string', 'default' => 'canary-fixture@example.invalid'],
            'retries' => ['type' => 'integer', 'default' => 3],
        ]];

        $dropped = (new ExamplePolicy())->drop(['components' => ['schemas' => ['Account' => $schema]]]);

        $this->assertSame(
            ['components' => ['schemas' => ['Account' => ['type' => 'object', 'properties' => [
                'email' => ['type' => 'string'],
                'retries' => ['type' => 'integer', 'default' => 3],
            ]]]]],
            $dropped,
        );
    }

    public function test_an_array_or_object_default_and_x_examples_are_removed(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'owner' => ['type' => 'object', 'default' => ['email' => 'canary-fixture@example.invalid'], 'x-examples' => ['a']],
            'tags' => ['type' => 'array', 'default' => ['canary-fixture']],
        ]];

        $dropped = (new ExamplePolicy())->drop(['components' => ['schemas' => ['Account' => $schema]]]);

        $this->assertSame(
            ['components' => ['schemas' => ['Account' => ['type' => 'object', 'properties' => [
                'owner' => ['type' => 'object'],
                'tags' => ['type' => 'array'],
            ]]]]],
            $dropped,
        );
    }

    public function test_schema_structure_is_unchanged_apart_from_example_fields(): void
    {
        $document = [
            'openapi' => '3.1.0',
            'components' => ['schemas' => ['User' => [
                'type' => 'object',
                'required' => ['id', 'name'],
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 7],
                    'name' => ['type' => 'string', 'enum' => ['Ada', 'Grace']],
                ],
                'allOf' => [['$ref' => '#/components/schemas/Base']],
            ]]],
        ];

        $expected = $document;
        unset($expected['components']['schemas']['User']['properties']['id']['example']);

        $this->assertSame($expected, (new ExamplePolicy())->drop($document));
    }

    public function test_a_property_or_schema_named_example_is_kept(): void
    {
        $document = ['components' => ['schemas' => [
            'example' => ['type' => 'object', 'properties' => ['example' => ['type' => 'string']]],
        ]]];

        $this->assertSame($document, (new ExamplePolicy())->drop($document));
    }
}
