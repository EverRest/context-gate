<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Classification\Catalog;
use ContextGate\Readiness\CheckStatus;
use ContextGate\Readiness\Checks\AbilitySetCheck;
use ContextGate\Readiness\Checks\TokenTableCheck;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Source\SchemaSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

final class SecondaryOrder extends Model
{
    protected $connection = 'secondary';

    protected $table = 'secondary_orders';
}

/** Models and token tables on a non-default connection are inspected on their own connection. */
class SecondaryConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.secondary' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::connection('secondary')->getSchemaBuilder()->create('secondary_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
        });
    }

    protected function tearDown(): void
    {
        Model::setConnectionResolver(app('db'));

        parent::tearDown();
    }

    public function test_schema_describe_reads_the_table_of_a_secondary_connection_model(): void
    {
        $result = (new SchemaSource(new Catalog(['secondary_orders' => ['status' => 'public']]), [SecondaryOrder::class]))
            ->read(new SourceRequest(['model' => SecondaryOrder::class]));

        $this->assertTrue($result->ok);
        $this->assertSame('status', $result->data['columns'][0]['name']);
    }

    public function test_classify_and_scan_see_the_secondary_connection_table(): void
    {
        config(['context-gate.sources.eloquent.models' => [SecondaryOrder::class]]);
        $root = sys_get_temp_dir().'/cg-sec-'.bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        app()->setBasePath($root);

        try {
            $this->assertSame(0, Artisan::call('gate:scan'));
            $this->assertStringContainsString('status', Artisan::output());

            $this->assertSame(0, Artisan::call('gate:classify'));
            $this->assertStringContainsString("'status'", (string) file_get_contents($root.'/app/Gate/classification.php'));
        } finally {
            @unlink($root.'/app/Gate/classification.php');
            @rmdir($root.'/app/Gate');
            @rmdir($root.'/app');
            @rmdir($root);
        }
    }

    /** Puts the token tables on 'secondary' only and makes the token model resolve there. */
    private function tokensOnSecondary(): void
    {
        $default = (string) config('database.default');
        config(['database.default' => 'secondary']);
        TokenFactory::migrate();
        config(['database.default' => $default]);

        $db = app('db');
        Model::setConnectionResolver(new class ($db) implements ConnectionResolverInterface {
            public function __construct(private DatabaseManager $db)
            {
            }

            public function connection($name = null)
            {
                return $this->db->connection($name ?? 'secondary');
            }

            public function getDefaultConnection()
            {
                return 'secondary';
            }

            public function setDefaultConnection($name)
            {
            }
        });
    }

    public function test_token_table_check_inspects_the_token_models_connection(): void
    {
        $this->tokensOnSecondary();

        $result = (new TokenTableCheck())->run(new ReadinessContext([], new Catalog([]), []));

        $this->assertSame(CheckStatus::Pass, $result->status);
    }

    public function test_ability_set_check_inspects_the_token_models_connection(): void
    {
        $this->tokensOnSecondary();
        DB::connection('secondary')->table('gate_access_tokens')->insert([
            'tokenable_type' => 'x', 'tokenable_id' => 1, 'name' => 't', 'token' => 'FAKE_FIXTURE_ONLY',
            'abilities' => json_encode(['evil:all']),
        ]);

        $result = (new AbilitySetCheck())->run(new ReadinessContext([], new Catalog([]), []));

        $this->assertSame(CheckStatus::Fail, $result->status);
    }
}
