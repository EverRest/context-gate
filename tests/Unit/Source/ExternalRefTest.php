<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Source\OpenApiSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/** FR-011 and research R4: an external $ref is listed as ref.external with no content and is never requested. */
class ExternalRefTest extends TestCase
{
    public function test_external_ref_is_listed_as_ref_external_without_content(): void
    {
        $result = $this->source()->read(new SourceRequest(['path' => 'openapi/external-ref.yaml']));

        $this->assertTrue($result->ok);
        $this->assertSame(['200' => ['$ref' => 'ref.external']], $result->data['endpoints'][0]['response_schemas']);
        $this->assertStringNotContainsString('example.com', (string) json_encode($result->data));
    }

    public function test_external_url_is_not_requested(): void
    {
        Http::preventStrayRequests();

        $this->source()->read(new SourceRequest(['path' => 'openapi/external-ref.yaml']));

        Http::assertNothingSent();
    }

    private function source(): OpenApiSource
    {
        return new OpenApiSource(dirname(__DIR__, 2) . '/Fixtures', ['openapi/']);
    }
}
