<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\RoleFixture;

require_once __DIR__ . '/../Fixtures/app/app/Models/User.php';

use App\Models\User;
use ContextGate\Server\SourceTools;
use ContextGate\Tests\TestCase;
use ContextGate\Tests\Unit\Source\DocsTree;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/** spec 003 FR-016 and R8: no source writes to the file system, the database or the configuration. */
class ReadOnlyTest extends TestCase
{
    use GateCallHarness;

    private const OLD_MTIME = 1_000_000_000;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = DocsTree::create();
        $this->app?->setBasePath($this->root);

        Gate::define('source.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(RoleFixture::stdio('alice', ['routes.list', 'schema.describe', 'config.keys', 'docs.read']));
        config(['context-gate.classification' => $this->catalog()]);
        $this->recordLines();
    }

    protected function tearDown(): void
    {
        DocsTree::remove($this->root);

        parent::tearDown();
    }

    /** @return array<string, array<string, string>> */
    private function catalog(): array
    {
        return [
            'users' => ['id' => 'public', 'name' => 'personal', 'email' => 'personal', 'password' => 'secret'],
            'source_routes' => array_fill_keys(['method', 'uri', 'name', 'controller', 'rule_names', 'middleware'], 'internal'),
            'source_config_keys' => array_fill_keys(['key', 'type', 'env'], 'internal'),
            'source_schema_items' => array_fill_keys(['model', 'table', 'kind', 'name', 'type', 'class', 'detail'], 'internal'),
            'source_docs' => array_fill_keys(['source', 'text', 'truncated'], 'internal'),
        ];
    }

    public function test_source_calls_leave_fixture_files_and_model_rows_unchanged(): void
    {
        $this->createUsersTable();
        DB::table('users')->insert(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'FAKE_FIXTURE_ONLY']);
        Route::get('api/orders', fn (): string => 'ok')->name('orders.list');
        config(['context-gate.sources.docs.paths' => ['docs/']]);
        config(['context-gate.sources.eloquent.models' => [User::class]]);
        config(['context-gate.sources.config.env_map' => ['app.name' => 'APP_NAME']]);
        config()->set('app.name', 'Fixture');
        $this->enable(['routes.list', 'schema.describe', 'config.keys', 'docs.read']);

        $files = $this->ageFiles();
        $rows = DB::table('users')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        DB::enableQueryLog();

        $this->assertFalse($this->callTool('routes.list', [])['isError']);
        $this->assertFalse($this->callTool('schema.describe', ['model' => User::class])['isError']);
        $this->assertFalse($this->callTool('config.keys', [])['isError']);
        $this->assertFalse($this->callTool('docs.read', ['path' => 'docs/guide.md'])['isError']);

        clearstatcache();
        foreach ($files as $path => $mtime) {
            $this->assertSame($mtime, filemtime($path), "The source changed the modification time of [{$path}].");
        }
        $this->assertSame($rows, DB::table('users')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all());
        $this->assertNotEmpty(DB::getQueryLog(), 'The query log must record the schema read, or the write check proves nothing.');
        $this->assertSame([], $this->writeQueries(DB::getQueryLog()));
    }

    /** @return array<string, int> path => the old modification time it was given */
    private function ageFiles(): array
    {
        $ages = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            $path = $file->getPathname();

            if (is_file($path) && ! is_link($path)) {
                touch($path, self::OLD_MTIME);
                $ages[$path] = self::OLD_MTIME;
            }
        }

        return $ages;
    }

    /**
     * @param array<array-key, array<string, mixed>> $log
     * @return list<string> the statements that write (anything but a select or a schema read)
     */
    private function writeQueries(array $log): array
    {
        $writes = [];

        foreach ($log as $query) {
            $statement = (string) $query['query'];

            if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $statement) === 1) {
                $writes[] = $statement;
            }
        }

        return $writes;
    }

    /** @param list<string> $names the tool names to enable */
    private function enable(array $names): void
    {
        $entries = SourceTools::entries();
        $tools = [];

        foreach ($names as $name) {
            $tools[$name] = ['enabled' => true, 'gate' => 'source.allow'] + $entries[$name];
        }

        config(['context-gate.enabled' => true, 'context-gate.tools' => $tools]);
    }

    private function createUsersTable(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
        });
    }
}
