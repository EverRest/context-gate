<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\RoleFixture;
use ContextGate\Server\SourceTools;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;

/** docs.read must cut to the profile's per-call limit, not the raw (null) config value, or the firewall drops the row. */
class DocsReadEffectiveLimitTest extends TestCase
{
    use GateCallHarness;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/cg-docs-limit-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/docs', 0777, true);
        file_put_contents($this->root . '/docs/big.md', implode('', array_map(static fn (int $i): string => "line {$i} of the guide\n", range(1, 4000))));
        $this->assertGreaterThan(80000, filesize($this->root . '/docs/big.md'));

        $this->app?->setBasePath($this->root);
        Gate::define('source.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.sources.docs.paths' => ['docs/'], 'context-gate.enabled' => true, ...RoleFixture::stdio('alice', ['docs.read'])]);
        config(['context-gate.classification' => ['source_docs' => ['source' => 'internal', 'text' => 'internal', 'truncated' => 'internal']]]);
        config(['context-gate.tools' => ['docs.read' => ['enabled' => true, 'gate' => 'source.allow'] + SourceTools::entries()['docs.read']]]);
        $this->recordLines();
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/docs/big.md');
        @rmdir($this->root . '/docs');
        @rmdir($this->root);
        parent::tearDown();
    }

    #[DataProvider('profiles')]
    public function test_large_doc_is_cut_to_the_effective_per_call_limit_not_dropped(string $profile, int $limit): void
    {
        config(['context-gate.profile' => $profile, 'app.env' => 'local']);

        $result = $this->callTool('docs.read', ['path' => 'docs/big.md']);

        $this->assertFalse($result["isError"]);
        $row = json_decode((string) $result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotSame([], $row);
        $this->assertTrue($row['truncated']);
        $text = $row['text']['text'];
        $this->assertNotSame('', $text);
        $this->assertLessThanOrEqual($limit, strlen($text));
        $this->assertStringEndsWith("\n", $text);
    }

    /** @return array<string, array{string, int}> */
    public static function profiles(): array
    {
        return ['strict' => ['strict', 16384], 'balanced' => ['balanced', 65536]];
    }
}
