<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Classification\Ceiling;
use ContextGate\Settings\ActiveSettings;
use ContextGate\Settings\EffectiveSettings;
use ContextGate\Settings\InvalidSettings;
use ContextGate\Settings\ToolEntry;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;

/** spec 009 FR-003, FR-005, research R2: typed getters on a valid document; every value read fails closed when invalid. */
class ActiveSettingsTypedTest extends TestCase
{
    /** @return array<string, mixed> the shipped config with one tool, one caller and a few values set */
    private function validDocument(): array
    {
        /** @var array<string, mixed> $document */
        $document = require dirname(__DIR__, 3).'/config/context-gate.php';
        $document['tools'] = ['probe' => ['enabled' => true, 'class' => ProbeTool::class, 'gate' => 'echo.allow', 'fingerprint' => null, 'contract' => null]];
        $document['callers'] = ['alice' => ['tools' => ['probe']]];
        $document['canaries'] = ['values' => ['FAKE_FIXTURE_ONLY']];
        $document['sources']['docs']['paths'] = ['docs/'];
        $document['sources']['eloquent']['models'] = ['App\\Models\\User'];
        $document['sources']['routes']['exclude'] = ['api/*'];
        $document['sources']['config']['env_map'] = ['services.x' => 'X_KEY'];
        $document['sources']['openapi']['examples'] = 'redacted';

        return $document;
    }

    public function test_the_getters_return_the_typed_values_of_a_valid_document(): void
    {
        $settings = new ActiveSettings($this->validDocument(), 'testing');

        $this->assertTrue($settings->valid());
        $this->assertFalse($settings->enabled());
        $this->assertEquals(['probe' => new ToolEntry('probe', ProbeTool::class, true, 'echo.allow', null, null)], $settings->tools());
        $this->assertSame(Ceiling::Internal, $settings->ceiling());
        $this->assertSame(['alice' => ['probe']], $settings->callers());
        $this->assertSame(['FAKE_FIXTURE_ONLY'], $settings->canaries());
        $this->assertSame('mcp/context-gate', $settings->httpPath());
        $this->assertSame('context-gate', $settings->channel());
        $this->assertSame([], $settings->origins());
        $this->assertSame([], $settings->openApiAllowlist());
        $this->assertSame(['docs/'], $settings->docsPaths());
        $this->assertSame([], $settings->openApiPaths());
        $this->assertSame(['App\\Models\\User'], $settings->models());
        $this->assertSame(['api/*'], $settings->routeExcludes());
        $this->assertSame(['services.x' => 'X_KEY'], $settings->envMap());
        $this->assertSame('redacted', $settings->examples());
        $this->assertNull($settings->cacheStore());
        $this->assertSame([], $settings->runbooks());
        $this->assertSame('testing', $settings->environment());
        $this->assertSame($this->validDocument(), $settings->document());
    }

    public function test_the_limits_are_the_effective_values_of_the_document(): void
    {
        $document = $this->validDocument();
        $settings = new ActiveSettings($document, 'testing');

        $this->assertSame(EffectiveSettings::for($document, 'testing'), $settings->limits());
        $this->assertSame(16384, $settings->limit('firewall.limits.per_call_bytes'));
    }

    public function test_the_classification_is_the_merged_map(): void
    {
        $settings = new ActiveSettings($this->validDocument(), 'testing');

        $this->assertArrayHasKey('source_routes', $settings->classification());
    }

    /** @return iterable<string, array{callable(ActiveSettings): mixed}> every getter that must refuse on an invalid document */
    public static function refusingGetters(): iterable
    {
        yield 'enabled' => [static fn (ActiveSettings $s): mixed => $s->enabled()];
        yield 'tools' => [static fn (ActiveSettings $s): mixed => $s->tools()];
        yield 'ceiling' => [static fn (ActiveSettings $s): mixed => $s->ceiling()];
        yield 'limits' => [static fn (ActiveSettings $s): mixed => $s->limits()];
        yield 'limit' => [static fn (ActiveSettings $s): mixed => $s->limit('firewall.limits.per_call_bytes')];
        yield 'classification' => [static fn (ActiveSettings $s): mixed => $s->classification()];
        yield 'callers' => [static fn (ActiveSettings $s): mixed => $s->callers()];
        yield 'canaries' => [static fn (ActiveSettings $s): mixed => $s->canaries()];
        yield 'httpPath' => [static fn (ActiveSettings $s): mixed => $s->httpPath()];
        yield 'origins' => [static fn (ActiveSettings $s): mixed => $s->origins()];
        yield 'openApiAllowlist' => [static fn (ActiveSettings $s): mixed => $s->openApiAllowlist()];
        yield 'docsPaths' => [static fn (ActiveSettings $s): mixed => $s->docsPaths()];
        yield 'openApiPaths' => [static fn (ActiveSettings $s): mixed => $s->openApiPaths()];
        yield 'models' => [static fn (ActiveSettings $s): mixed => $s->models()];
        yield 'routeExcludes' => [static fn (ActiveSettings $s): mixed => $s->routeExcludes()];
        yield 'envMap' => [static fn (ActiveSettings $s): mixed => $s->envMap()];
        yield 'examples' => [static fn (ActiveSettings $s): mixed => $s->examples()];
        yield 'cacheStore' => [static fn (ActiveSettings $s): mixed => $s->cacheStore()];
        yield 'runbooks' => [static fn (ActiveSettings $s): mixed => $s->runbooks()];
    }

    /** @param callable(ActiveSettings): mixed $read */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusingGetters')]
    public function test_every_getter_refuses_an_invalid_document(callable $read): void
    {
        $document = $this->validDocument();
        $document['http']['path'] = 123;
        $settings = new ActiveSettings($document, 'testing');

        $this->assertFalse($settings->valid());
        $this->expectException(InvalidSettings::class);

        $read($settings);
    }

    public function test_the_channel_and_the_document_stay_readable_when_invalid(): void
    {
        $document = $this->validDocument();
        $document['http']['path'] = 123;
        $settings = new ActiveSettings($document, 'testing');

        $this->assertSame('context-gate', $settings->channel());
        $this->assertSame($document, $settings->document());
        $this->assertSame('testing', $settings->environment());
        $this->assertSame(['http.path'], array_column($settings->violations(), 'key'));
    }
}
