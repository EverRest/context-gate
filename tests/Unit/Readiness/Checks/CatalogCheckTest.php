<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness\Checks;

use ContextGate\Classification\Catalog;
use ContextGate\Readiness\CheckStatus;
use ContextGate\Readiness\Checks\CatalogCheck;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Tests\Fixtures\RelationProbeContract;
use ContextGate\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** T021, spec 002 FR-001 and FR-006: every contract column is classified and no draft marker remains. */
class CatalogCheckTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/context-gate-catalog-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/app/Gate', 0777, true);
        $this->app?->setBasePath($this->root);
    }

    protected function tearDown(): void
    {
        @unlink($this->root.'/app/Gate/classification.php');
        @rmdir($this->root.'/app/Gate');
        @rmdir($this->root.'/app');
        @rmdir($this->root);

        parent::tearDown();
    }

    public function test_a_contract_column_missing_from_the_catalog_fails_naming_it(): void
    {
        $ctx = new ReadinessContext(
            [],
            new Catalog(['orders' => ['status' => 'public'], 'customers' => ['name' => 'public']]),
            [new RelationProbeContract()],
        );

        $result = (new CatalogCheck())->run($ctx);

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame('classification.orders.customer_id', $result->subject);
        $this->assertStringContainsString('customer_id', $result->message);
    }

    /** @return array<string, array{string}> */
    public static function draftMarkers(): array
    {
        return [
            'needs decision' => ["<?php\n\nreturn [\n    'name' => 'internal', // needs decision\n];\n"],
            'suggested' => ["<?php\n\nreturn [\n    'name' => 'internal', // suggested\n];\n"],
        ];
    }

    #[DataProvider('draftMarkers')]
    public function test_a_draft_marker_in_the_catalog_file_fails_naming_the_file_and_line(string $contents): void
    {
        file_put_contents($this->root.'/app/Gate/classification.php', $contents);
        $ctx = new ReadinessContext([], new Catalog([]), []);

        $result = (new CatalogCheck())->run($ctx);

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame('app/Gate/classification.php', $result->subject);
        $this->assertStringContainsString('line 4', $result->message);
    }
}
