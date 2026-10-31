<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\OpenApi;

use ContextGate\Server\ReasonCode;
use ContextGate\Source\OpenApiParser;
use ContextGate\Source\SourceFailure;
use ContextGate\Tests\TestCase;

/** R1 (parser choice, version check), FR-010 (2 MB cap checked before parsing). */
class OpenApiParserTest extends TestCase
{
    public function test_json_3_1_document_is_parsed(): void
    {
        $document = (new OpenApiParser())->parse($this->fixture('valid-3.1.json'), 'json');

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertArrayHasKey('/users/{id}', $document['paths']);
        $this->assertArrayHasKey('/users', $document['paths']);
    }

    public function test_yaml_3_0_document_is_parsed(): void
    {
        $document = (new OpenApiParser())->parse($this->fixture('valid-3.0.yaml'), 'yaml');

        $this->assertSame('3.0.3', $document['openapi']);
        $this->assertArrayHasKey('/users', $document['paths']);
    }

    public function test_swagger_2_is_refused_with_openapi_version_and_no_content(): void
    {
        $reason = $this->refusedWith($this->fixture('swagger-2.json'), 'json');

        $this->assertSame(ReasonCode::OpenApiVersion, $reason);
    }

    public function test_document_without_openapi_field_is_refused_with_openapi_version(): void
    {
        $this->assertSame(ReasonCode::OpenApiVersion, $this->refusedWith('{"info":{"title":"x"}}', 'json'));
    }

    public function test_document_over_2_mb_is_refused_before_parsing(): void
    {
        $document = $this->fixture('oversized.json');

        $this->assertSame(2 * 1024 * 1024 + 1, strlen($document));
        $this->assertSame(ReasonCode::SourceTooLarge, $this->refusedWith($document, 'json'));
    }

    public function test_malformed_json_is_refused_with_source_refused(): void
    {
        $this->assertSame(ReasonCode::SourceRefused, $this->refusedWith('{"openapi": "3.1.0",', 'json'));
    }

    public function test_json_nested_deeper_than_64_is_refused_with_source_refused(): void
    {
        $deep = str_repeat('[', 70) . str_repeat(']', 70);

        $this->assertSame(ReasonCode::SourceRefused, $this->refusedWith($deep, 'json'));
    }

    public function test_unknown_format_is_refused_and_never_parsed_as_json(): void
    {
        $this->assertSame(ReasonCode::SourceRefused, $this->refusedWith($this->fixture('valid-3.1.json'), 'xml'));
    }

    public function test_refusal_message_holds_no_document_content(): void
    {
        try {
            (new OpenApiParser())->parse($this->fixture('swagger-2.json'), 'json');
            $this->fail('A Swagger 2.0 document must be refused');
        } catch (SourceFailure $refused) {
            $this->assertStringNotContainsString('Legacy API', $refused->getMessage());
            $this->assertStringNotContainsString('listUsers', $refused->getMessage());
        }
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/openapi/' . $name);
    }

    private function refusedWith(string $document, string $format): ReasonCode
    {
        try {
            (new OpenApiParser())->parse($document, $format);
        } catch (SourceFailure $refused) {
            return $refused->reason;
        }

        $this->fail('The document must be refused');
    }
}
