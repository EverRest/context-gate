<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Source\OpenApiSource;
use ContextGate\Source\SourceRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** FR-003 and research R3: examples are dropped by default; examples: redacted keeps them for the firewall; any other value drops. */
class ExamplesModeTest extends TestCase
{
    private const EXAMPLE = [
        'id' => 1,
        'name' => 'Fixture Person',
        'email' => 'canary-fixture@example.invalid',
        'token' => 'FAKE_FIXTURE_ONLY_TOKEN_0001',
    ];

    /**
     * @param array<string, mixed> $options named constructor arguments, such as examples
     * @return array<string, mixed> the first endpoint row of the fixture
     */
    private function firstRow(array $options = []): array
    {
        $source = new OpenApiSource(dirname(__DIR__, 2) . '/Fixtures', ['openapi/'], ...$options);

        $result = $source->read(new SourceRequest(['path' => 'openapi/examples-in-schema.json']));
        $this->assertTrue($result->ok);

        return $result->data['endpoints'][0];
    }

    public function test_drop_mode_removes_the_example_and_keeps_the_schema(): void
    {
        $schema = $this->firstRow(['examples' => 'drop'])['response_schemas']['200'];

        $this->assertArrayNotHasKey('example', $schema);
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('email', $schema['properties']);
    }

    public function test_drop_is_the_default_when_no_mode_is_given(): void
    {
        $this->assertSame($this->firstRow(['examples' => 'drop']), $this->firstRow());
    }

    public function test_redacted_mode_passes_the_example_structure_to_the_source_output(): void
    {
        $schema = $this->firstRow(['examples' => 'redacted'])['response_schemas']['200'];

        $this->assertSame(self::EXAMPLE, $schema['example']);
        $this->assertSame('object', $schema['type']);
    }

    /** @return array<string, array{mixed}> */
    public static function unknownModes(): array
    {
        return [
            'other word' => ['keep'],
            'capitalised' => ['Redacted'],
            'empty string' => [''],
            'null' => [null],
            'number' => [1],
        ];
    }

    #[DataProvider('unknownModes')]
    public function test_an_unknown_mode_falls_back_to_drop(mixed $mode): void
    {
        $schema = $this->firstRow(['examples' => $mode])['response_schemas']['200'];

        $this->assertArrayNotHasKey('example', $schema);
        $this->assertStringNotContainsString('FAKE_FIXTURE_ONLY', (string) json_encode($schema));
    }
}
