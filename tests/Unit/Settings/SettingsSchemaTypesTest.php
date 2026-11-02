<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Settings\SettingsSchema;
use ContextGate\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** spec 009 FR-004, research R6: every key that was `any` has an exact type, and a wrong type is reported with its key. */
class SettingsSchemaTypesTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, mixed>}> the key and a document that holds one wrong value */
    public static function wrongValues(): iterable
    {
        yield 'callers tools as a string' => ['callers.alice.tools', ['callers' => ['alice' => ['tools' => 'probe']]]];
        yield 'callers tools with an integer item' => ['callers.alice.tools', ['callers' => ['alice' => ['tools' => [1]]]]];
        yield 'canary values as a string' => ['canaries.values', ['canaries' => ['values' => 'secret']]];
        yield 'canary values with an integer item' => ['canaries.values', ['canaries' => ['values' => [1]]]];
        yield 'http path as an integer' => ['http.path', ['http' => ['path' => 123]]];
        yield 'http path empty' => ['http.path', ['http' => ['path' => '']]];
        yield 'http origins as a string' => ['http.origins', ['http' => ['origins' => 'https://app.test']]];
        yield 'channel as a list' => ['channel', ['channel' => ['context-gate']]];
        yield 'channel empty' => ['channel', ['channel' => '']];
        yield 'routes exclude as a string' => ['sources.routes.exclude', ['sources' => ['routes' => ['exclude' => 'api/*']]]];
        yield 'eloquent models with an integer item' => ['sources.eloquent.models', ['sources' => ['eloquent' => ['models' => [1]]]]];
        yield 'config env_map as a list' => ['sources.config.env_map', ['sources' => ['config' => ['env_map' => ['services.x']]]]];
        yield 'config env_map with an integer value' => ['sources.config.env_map', ['sources' => ['config' => ['env_map' => ['services.x' => 5]]]]];
        yield 'docs paths as a string' => ['sources.docs.paths', ['sources' => ['docs' => ['paths' => 'docs/']]]];
        yield 'openapi paths as a string' => ['sources.openapi.paths', ['sources' => ['openapi' => ['paths' => 'openapi/']]]];
        yield 'openapi allowlist as a string' => ['sources.openapi.allowlist', ['sources' => ['openapi' => ['allowlist' => 'api.example.test']]]];
        yield 'openapi examples as keep' => ['sources.openapi.examples', ['sources' => ['openapi' => ['examples' => 'keep']]]];
    }

    /** @param array<string, mixed> $document */
    #[DataProvider('wrongValues')]
    public function test_a_wrong_type_is_reported_with_its_key(string $key, array $document): void
    {
        $this->assertContains($key, array_column(SettingsSchema::violations($document), 'key'));
    }

    public function test_correct_types_pass(): void
    {
        $document = [
            'callers' => ['alice' => ['tools' => ['probe']]],
            'canaries' => ['values' => ['FAKE_FIXTURE_ONLY']],
            'http' => ['path' => 'mcp/context-gate', 'origins' => ['https://app.test']],
            'channel' => 'context-gate',
            'sources' => [
                'routes' => ['exclude' => ['api/*']],
                'eloquent' => ['models' => ['App\\Models\\User']],
                'config' => ['env_map' => ['services.x' => 'X_KEY']],
                'docs' => ['paths' => ['docs/']],
                'openapi' => ['paths' => ['openapi/'], 'allowlist' => ['api.example.test'], 'examples' => 'redacted'],
            ],
        ];

        $this->assertSame([], SettingsSchema::violations($document));
    }
}
